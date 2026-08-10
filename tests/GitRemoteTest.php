<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\GitRemote;
use Porta\Support\Outcome;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Revolt\EventLoop;

/**
 * Drives GitRemote against purely local remotes: a bare repository plus two
 * clones, so fetch/pull/push run for real without any network. Skipped when
 * git is absent.
 */
final class GitRemoteTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;

    private TempProject $bare;
    private TempProject $clone1;
    private TempProject $clone2;

    private function git(TempProject $project, string $command): string
    {
        return $project->git(...explode(' ', $command));
    }

    /** A bare origin with one commit, cloned twice. */
    private function remotes(): GitRemote
    {
        $this->skipWithoutGit();
        $this->bare = new TempProject();
        $this->clone1 = new TempProject();
        $this->clone2 = new TempProject();
        $this->git($this->bare, 'init -q --bare .');
        foreach ([$this->clone1, $this->clone2] as $clone) {
            $this->git($clone, 'clone -q '.$this->bare->dir.' .');
            $this->git($clone, 'config user.email t@t');
            $this->git($clone, 'config user.name t');
            $this->git($clone, 'config commit.gpgsign false');
            // Hermetic push semantics regardless of the developer's global config.
            $this->git($clone, 'config push.autoSetupRemote false');
            $this->git($clone, 'config push.default simple');
        }
        file_put_contents($this->clone1->path('a.txt'), data: "one\n");
        $this->git($this->clone1, 'add -A');
        $this->git($this->clone1, 'commit -qm init');
        $this->git($this->clone1, 'push -qu origin HEAD');
        $this->git($this->clone2, 'pull -q origin');
        $this->git($this->clone2, 'branch -q --set-upstream-to origin/'.trim($this->git($this->clone2, 'rev-parse --abbrev-ref HEAD')));

        return new GitRemote($this->clone2->dir);
    }

    /** Commit in clone1 and push, so clone2's origin is ahead. */
    private function advanceOrigin(): void
    {
        file_put_contents($this->clone1->path('a.txt'), data: "one\ntwo\n");
        $this->git($this->clone1, 'commit -aqm second');
        $this->git($this->clone1, 'push -q origin HEAD');
    }

    public function testFetchAndFastForwardPull(): void
    {
        $remote = $this->remotes();
        $this->advanceOrigin();

        $results = [];
        $remote->fetch(static function (Outcome $ok, string $out) use (&$results): void {
            $results['fetch'] = $ok->isOk();
        });
        $this->await(static function () use (&$results): bool {
            return array_key_exists('fetch', $results);
        });
        $this->assertTrue($results['fetch']);

        $remote->pull(static function (Outcome $ok, string $out) use (&$results): void {
            $results['pull'] = $ok->isOk();
        });
        $this->await(static function () use (&$results): bool {
            return array_key_exists('pull', $results);
        });
        $this->assertTrue($results['pull']);
        $this->assertSame("one\ntwo\n", file_get_contents($this->clone2->path('a.txt')), 'the pull fast-forwarded the file');
    }

    public function testDivergedPullFailsCleanlyWithGitsMessage(): void
    {
        $remote = $this->remotes();
        $this->advanceOrigin();
        // A local commit in clone2 diverges from origin.
        file_put_contents($this->clone2->path('a.txt'), data: "one\nlocal\n");
        $this->git($this->clone2, 'commit -aqm local');

        $result = null;
        $remote->pull(static function (Outcome $ok, string $out) use (&$result): void {
            $result = ['ok' => $ok->isOk(), 'out' => $out];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertFalse($result['ok']);
        $this->assertStringContainsStringIgnoringCase('fast-forward', $result['out'], "git's own refusal is surfaced");
    }

    public function testPushLandsOnTheBareRemote(): void
    {
        $remote = $this->remotes();
        file_put_contents($this->clone2->path('b.txt'), data: "new\n");
        $this->git($this->clone2, 'add -A');
        $this->git($this->clone2, 'commit -qm frompush');

        $result = null;
        $remote->push(static function (Outcome $ok, string $out) use (&$result): void {
            $result = $ok->isOk();
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertTrue($result);
        $this->assertStringContainsString('frompush', $this->git($this->bare, 'log --format=%s'), 'the commit reached origin');
    }

    public function testPushWithoutUpstreamSurfacesTheHintAndSetUpstreamFixesIt(): void
    {
        $remote = $this->remotes();
        $this->git($this->clone2, 'checkout -qb feature');
        file_put_contents($this->clone2->path('c.txt'), data: "x\n");
        $this->git($this->clone2, 'add -A');
        $this->git($this->clone2, 'commit -qm feature');

        $result = null;
        $remote->push(static function (Outcome $ok, string $out) use (&$result): void {
            $result = ['ok' => $ok->isOk(), 'out' => $out];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('upstream', $result['out']);

        $upstream = null;
        $remote->pushSetUpstream(static function (Outcome $ok) use (&$upstream): void {
            $upstream = $ok->isOk();
        });
        $this->await(static function () use (&$upstream): bool {
            return null !== $upstream;
        });
        $this->assertTrue($upstream);
        $this->assertStringContainsString('feature', $this->git($this->bare, 'branch'), 'the branch reached origin');
    }

    public function testUnreachableRemoteFailsWithStderr(): void
    {
        $remote = $this->remotes();
        $this->git($this->clone2, 'remote set-url origin /nonexistent-origin');

        $result = null;
        $remote->fetch(static function (Outcome $ok, string $out) use (&$result): void {
            $result = ['ok' => $ok->isOk(), 'out' => $out];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertFalse($result['ok']);
        $this->assertNotSame('', $result['out'], 'the failure carries stderr detail');
    }

    public function testBusyWhileInFlightAndCancelAll(): void
    {
        $remote = $this->remotes();
        $this->assertFalse($remote->isBusy());
        $remote->fetch(static function (): void {});
        $this->assertTrue($remote->isBusy(), 'the fetch is in flight');
        $remote->cancelAll();
        $this->assertFalse($remote->isBusy());
    }

    public function testExplicitGitPathWinsOverDiscovery(): void
    {
        $plain = new TempProject();
        $remote = new GitRemote($plain->dir, '/custom/bin/git');
        $this->assertSame('/custom/bin/git', Probe::get($remote, 'git'));
    }

    public function testUnavailableOutsideARepository(): void
    {
        $this->skipWithoutGit();
        $plain = new TempProject();
        $remote = new GitRemote($plain->dir);

        $this->assertFalse($remote->isAvailable());
        $result = null;
        $remote->pull(static function (Outcome $ok, string $out) use (&$result): void {
            $result = ['ok' => $ok->isOk(), 'out' => $out];
        });
        $this->assertSame(['ok' => false, 'out' => 'git is not available'], $result);
    }

    public function testDivergedPullRebaseIntegrates(): void
    {
        $remote = $this->remotes();
        $this->advanceOrigin();
        file_put_contents($this->clone2->path('b.txt'), data: "local\n");
        $this->git($this->clone2, 'add -A');
        $this->git($this->clone2, 'commit -qm local');

        $result = null;
        $remote->pullRebase(static function (Outcome $ok, string $out) use (&$result): void {
            $result = ['ok' => $ok->isOk(), 'out' => $out];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertTrue($result['ok'], $result['out']);
        $this->assertSame("one\ntwo\n", file_get_contents($this->clone2->path('a.txt')), 'the remote commit landed');
        $this->assertFileExists($this->clone2->path('b.txt'), 'the local commit was rebased on top');
    }

    public function testDivergedPullMergeIntegrates(): void
    {
        $remote = $this->remotes();
        $this->advanceOrigin();
        file_put_contents($this->clone2->path('b.txt'), data: "local\n");
        $this->git($this->clone2, 'add -A');
        $this->git($this->clone2, 'commit -qm local');

        $result = null;
        $remote->pullMerge(static function (Outcome $ok, string $out) use (&$result): void {
            $result = ['ok' => $ok->isOk(), 'out' => $out];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertTrue($result['ok'], $result['out']);
        $this->assertStringContainsString('Merge', $this->git($this->clone2, 'log -1 --format=%s'), 'a merge commit integrated the branches');
    }
}
