<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Process;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Porta\Config\WorkspaceTrust;
use Porta\GitPlugin\Process\GitRemote;
use Porta\Support\Outcome;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UntrustsProjects;

/**
 * Drives GitRemote against purely local remotes: a bare repository plus two
 * clones, so fetch/pull/push run for real without any network. Skipped when
 * git is absent.
 */
final class GitRemoteTest extends TestCase
{
    use GitBinary;
    use PreservesPath;
    use PumpsLoop;
    use UntrustsProjects;

    private TempProject $bare;
    private TempProject $clone1;
    private TempProject $clone2;

    #[After]
    protected function forgetTheUsersConfig(): void
    {
        \putenv('GIT_CONFIG_GLOBAL');
    }

    private function git(TempProject $project, string $command): string
    {
        return $project->git(...\explode(' ', $command));
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
        \file_put_contents($this->clone1->path('a.txt'), data: "one\n");
        $this->git($this->clone1, 'add -A');
        $this->git($this->clone1, 'commit -qm init');
        $this->git($this->clone1, 'push -qu origin HEAD');
        $this->git($this->clone2, 'pull -q origin');
        $this->git($this->clone2, 'branch -q --set-upstream-to origin/'.\trim($this->git($this->clone2, 'rev-parse --abbrev-ref HEAD')));

        return new GitRemote($this->clone2->dir);
    }

    /** Commit in clone1 and push, so clone2's origin is ahead. */
    private function advanceOrigin(): void
    {
        \file_put_contents($this->clone1->path('a.txt'), data: "one\ntwo\n");
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
            return \array_key_exists('fetch', $results);
        });
        $this->assertTrue($results['fetch']);

        $remote->pull(static function (Outcome $ok, string $out) use (&$results): void {
            $results['pull'] = $ok->isOk();
        });
        $this->await(static function () use (&$results): bool {
            return \array_key_exists('pull', $results);
        });
        $this->assertTrue($results['pull']);
        $this->assertSame("one\ntwo\n", \file_get_contents($this->clone2->path('a.txt')), 'the pull fast-forwarded the file');
    }

    /**
     * An untrusted clone's own config names a program for the pull's every
     * step — the upload-pack a local remote runs, a hook after the merge —
     * and its tree a filter for the file pulled: the pull runs none of
     * them, yet still pulls. Trusted, all three run: the control.
     */
    public function testAnUntrustedRepositorysProgramsDoNotRunOnAPull(): void
    {
        $this->untrustProjects();
        $this->remotes();
        $tools = new TempProject([
            'hook.sh' => "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\n",
            'upload-pack.sh' => "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\nexec git-upload-pack \"\$@\"\n",
        ]);
        \chmod($tools->path('hook.sh'), permissions: 0o755);
        \chmod($tools->path('upload-pack.sh'), permissions: 0o755);
        $this->clone2->write('.git/hooks/post-merge', "#!/bin/sh\nexec ".\escapeshellarg($tools->path('hook.sh'))."\n");
        \chmod($this->clone2->path('.git/hooks/post-merge'), permissions: 0o755);
        $this->clone2->git('config', 'remote.origin.uploadpack', $tools->path('upload-pack.sh'));
        // and its tree names a filter of the user's own for the file the pull
        // brings: attributes an untrusted tree has none of
        $tools->write('smudge.sh', "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\ncat\n");
        \chmod($tools->path('smudge.sh'), permissions: 0o755);
        $tools->write('.gitconfig', "[filter \"own\"]\n\tsmudge = ".$tools->path('smudge.sh')."\n");
        \file_put_contents($this->clone1->path('.gitattributes'), data: "a.txt filter=own\n");
        $this->git($this->clone1, 'add .gitattributes');
        \putenv('GIT_CONFIG_GLOBAL='.$tools->path('.gitconfig'));
        $pull = function () use ($tools): bool {
            \array_map(\unlink(...), (array) \glob($tools->path('ran-*')));
            \file_put_contents($this->clone1->path('a.txt'), data: "more\n", flags: \FILE_APPEND);
            $this->git($this->clone1, 'commit -aqm more');
            $this->git($this->clone1, 'push -q origin HEAD');
            $pulled = null;
            new GitRemote($this->clone2->dir)->pull(static function (Outcome $ok) use (&$pulled): void {
                $pulled = $ok->isOk();
            });
            $this->await(static function () use (&$pulled): bool {
                return null !== $pulled;
            }, what: 'the pull to answer');

            return (bool) $pulled;
        };

        $this->assertTrue($pull(), 'untrusted, it still pulls');
        $this->assertSame([], \glob($tools->path('ran-*')), 'and runs nothing the clone names');

        new WorkspaceTrust($this->untrustingHome->dir)->trust($this->clone2->dir);
        $this->assertTrue($pull());
        $this->assertCount(3, (array) \glob($tools->path('ran-*')), 'trusted, all three run');
    }

    /** And a push: the receive-pack its config names for a local remote does not run, and the push lands. */
    public function testAnUntrustedRepositorysReceivePackDoesNotRunOnAPush(): void
    {
        $this->untrustProjects();
        $remote = $this->remotes();
        $tools = new TempProject(['receive-pack.sh' => "#!/bin/sh\ntouch \"\$(dirname \"\$0\")/ran-\$\$\"\nexec git-receive-pack \"\$@\"\n"]);
        \chmod($tools->path('receive-pack.sh'), permissions: 0o755);
        $this->clone2->git('config', 'remote.origin.receivepack', $tools->path('receive-pack.sh'));
        \file_put_contents($this->clone2->path('a.txt'), data: "pushed\n");
        $this->git($this->clone2, 'commit -aqm pushed');

        $pushed = null;
        $remote->push(static function (Outcome $ok) use (&$pushed): void {
            $pushed = $ok->isOk();
        });
        $this->await(static function () use (&$pushed): bool {
            return null !== $pushed;
        }, what: 'the push to answer');

        $this->assertTrue($pushed);
        $this->assertSame($this->git($this->clone2, 'rev-parse HEAD'), $this->git($this->bare, 'rev-parse HEAD'), 'it landed');
        $this->assertSame([], \glob($tools->path('ran-*')));
    }

    public function testDivergedPullFailsCleanlyWithGitsMessage(): void
    {
        $remote = $this->remotes();
        $this->advanceOrigin();
        // A local commit in clone2 diverges from origin.
        \file_put_contents($this->clone2->path('a.txt'), data: "one\nlocal\n");
        $this->git($this->clone2, 'commit -aqm local');

        $result = $this->awaitOutcome($remote->pull(...));
        $this->assertFalse($result['ok']);
        $this->assertStringContainsStringIgnoringCase('fast-forward', $result['out'], "git's own refusal is surfaced");
    }

    public function testPushLandsOnTheBareRemote(): void
    {
        $remote = $this->remotes();
        \file_put_contents($this->clone2->path('b.txt'), data: "new\n");
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
        \file_put_contents($this->clone2->path('c.txt'), data: "x\n");
        $this->git($this->clone2, 'add -A');
        $this->git($this->clone2, 'commit -qm feature');

        $result = $this->awaitOutcome($remote->push(...));
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

    public function testSetUpstreamPushesToTheOneRemoteWhateverItsName(): void
    {
        $remote = $this->remotes();
        $this->git($this->clone2, 'remote rename origin upstream');
        $this->git($this->clone2, 'checkout -qb feature');

        $result = $this->awaitOutcome($remote->pushSetUpstream(...));

        $this->assertTrue($result['ok'], $result['out']);
        $this->assertStringContainsString('upstream/feature', $this->git($this->clone2, 'rev-parse --abbrev-ref @{upstream}'));
    }

    public function testAmongSeveralRemotesSetUpstreamPushesToOrigin(): void
    {
        $remote = $this->remotes();
        $this->git($this->clone2, 'remote add mirror /nonexistent-mirror');
        $this->git($this->clone2, 'checkout -qb feature');

        $result = $this->awaitOutcome($remote->pushSetUpstream(...));

        $this->assertTrue($result['ok'], $result['out']);
        $this->assertStringContainsString('origin/feature', $this->git($this->clone2, 'rev-parse --abbrev-ref @{upstream}'));
    }

    public function testUnreachableRemoteFailsWithStderr(): void
    {
        $remote = $this->remotes();
        $this->git($this->clone2, 'remote set-url origin /nonexistent-origin');

        $result = $this->awaitOutcome($remote->fetch(...));
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
        $project = new TempProject();
        $project->initGit(name: 'tester', email: 't@t');
        $remote = new GitRemote($project->dir, '/custom/bin/git');

        // Availability is the observable: a configured path is used verbatim,
        // so a repository with one set reads as available whatever discovery
        // would have found.
        $this->assertTrue($remote->isAvailable());
    }

    public function testAClosurePathIsReReadPerCallSoASettingsEditApplies(): void
    {
        // The plugin hands a closure over its `path` setting; resolving once at
        // construction is what used to make the setting restart-required.
        \putenv('PATH=/nonexistent');
        $project = new TempProject();
        $project->initGit(name: 'tester', email: 't@t');
        $configured = null;
        $remote = new GitRemote($project->dir, static function () use (&$configured): ?string {
            return $configured;
        });
        $this->assertFalse($remote->isAvailable(), 'nothing configured, nothing on PATH');

        $configured = '/custom/bin/git';

        $this->assertTrue($remote->isAvailable(), 'the new value is picked up with no rebuild');
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

        $upstream = null;
        $remote->pushSetUpstream(static function (Outcome $ok, string $out) use (&$upstream): void {
            $upstream = ['ok' => $ok->isOk(), 'out' => $out];
        });
        $this->assertSame(['ok' => false, 'out' => 'git is not available'], $upstream, 'no remote listed to push to: the same answer');
    }

    public function testDivergedPullRebaseIntegrates(): void
    {
        $remote = $this->remotes();
        $this->advanceOrigin();
        \file_put_contents($this->clone2->path('b.txt'), data: "local\n");
        $this->git($this->clone2, 'add -A');
        $this->git($this->clone2, 'commit -qm local');

        $result = $this->awaitOutcome($remote->pullRebase(...));
        $this->assertTrue($result['ok'], $result['out']);
        $this->assertSame("one\ntwo\n", \file_get_contents($this->clone2->path('a.txt')), 'the remote commit landed');
        $this->assertFileExists($this->clone2->path('b.txt'), 'the local commit was rebased on top');
    }

    public function testDivergedPullMergeIntegrates(): void
    {
        $remote = $this->remotes();
        $this->advanceOrigin();
        \file_put_contents($this->clone2->path('b.txt'), data: "local\n");
        $this->git($this->clone2, 'add -A');
        $this->git($this->clone2, 'commit -qm local');

        $result = $this->awaitOutcome($remote->pullMerge(...));
        $this->assertTrue($result['ok'], $result['out']);
        $this->assertStringContainsString('Merge', $this->git($this->clone2, 'log -1 --format=%s'), 'a merge commit integrated the branches');
    }

    public function testNothingItRunsMayAskTheTerminalAndAStallEnds(): void
    {
        $remote = $this->remotes();
        $seen = $this->clone2->path('ssh-env');
        $ssh = $this->clone2->path('fake-ssh');
        \file_put_contents($ssh, data: "#!/bin/sh\nprintf '%s|%s|%s' \"\$SSH_ASKPASS_REQUIRE\" \"\$SSH_ASKPASS\" \"\$GIT_HTTP_LOW_SPEED_TIME\" > ".\escapeshellarg($seen)."\nexit 1\n");
        \chmod($ssh, permissions: 0o755);
        $this->git($this->clone2, 'remote set-url origin ssh://example.invalid/repo.git');
        \putenv('GIT_SSH_COMMAND='.$ssh);
        try {
            $done = false;
            $remote->fetch(static function () use (&$done): void {
                $done = true;
            });
            $this->await(static function () use (&$done): bool {
                return $done;
            }, what: 'the fetch to fail');
            $this->assertSame('force|false|30', \file_get_contents($seen), 'ssh asks a program that says no; a stalled transfer ends');

            \putenv('SSH_ASKPASS=/usr/local/bin/my-askpass');
            $done = false;
            $remote->fetch(static function () use (&$done): void {
                $done = true;
            });
            $this->await(static function () use (&$done): bool {
                return $done;
            }, what: 'the second fetch');
            $this->assertSame('force|/usr/local/bin/my-askpass|30', \file_get_contents($seen), 'what the user set wins');
        } finally {
            \putenv('GIT_SSH_COMMAND');
            \putenv('SSH_ASKPASS');
        }
    }
}
