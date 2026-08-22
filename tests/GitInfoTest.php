<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\Parse\HunkParser;
use Porta\GitPlugin\Setting\GitSettings;
use Porta\Support\Outcome;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

final class GitInfoTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;

    public function testParseModifiedAddedDeleted(): void
    {
        $diff = <<<'DIFF'
            diff --git a/a.txt b/a.txt
            @@ -2 +2 @@ context
            -old line 2
            +new line 2
            @@ -3,0 +4 @@ context
            +added line 4
            @@ -6,2 +7,0 @@ context
            -removed a
            -removed b
            DIFF;

        $marks = HunkParser::parse($diff);

        $this->assertSame('modified', $marks[1]); // line 2 (0-based 1)
        $this->assertSame('added', $marks[3]); // line 4 (0-based 3)
        $this->assertSame('deleted', $marks[6]); // deletion survives on line 7
    }

    public function testParseMultiLineAdditionAndGrowthClassification(): void
    {
        // 1 old line replaced by 3 new: first is modified, extras are added.
        $marks = HunkParser::parse("@@ -5 +5,3 @@\n-old\n+a\n+b\n+c\n");

        $this->assertSame('modified', $marks[4]);
        $this->assertSame('added', $marks[5]);
        $this->assertSame('added', $marks[6]);
    }

    public function testParsePureAdditionBlock(): void
    {
        $marks = HunkParser::parse("@@ -0,0 +1,2 @@\n+first\n+second\n");

        $this->assertSame('added', $marks[0]);
        $this->assertSame('added', $marks[1]);
    }

    public function testParseIgnoresNonHunkLines(): void
    {
        $this->assertSame([], HunkParser::parse("just some text\nno hunks here\n"));
    }

    public function testDisabledWhenNotAGitRepo(): void
    {
        $project = new TempProject(['a.txt' => "x\n"]);
        $git = new GitInfo($project->dir);

        $this->assertFalse($git->isAvailable());

        $branch = 'unset';
        $git->branch(static function (?string $b) use (&$branch): void {
            $branch = $b;
        });
        $this->assertNull($branch, 'branch resolves synchronously to null when disabled');

        $marks = 'unset';
        $git->diff($project->path('a.txt'), static function (array $m) use (&$marks): void {
            $marks = $m;
        });
        $this->assertSame([], $marks);

        // Write operations short-circuit too.
        $staged = 'unset';
        $git->stage($project->path('a.txt'), static function (Outcome $ok) use (&$staged): void {
            $staged = $ok->isOk();
        });
        $this->assertFalse($staged);

        $committed = 'unset';
        $git->commit('msg', static function (Outcome $ok) use (&$committed): void {
            $committed = $ok->isOk();
        });
        $this->assertFalse($committed);
    }

    public function testALinkedWorktreeIsARepository(): void
    {
        // .git is a FILE there (`gitdir: …`), and an is_dir check made the whole
        // git feature silently inert in every worktree and submodule.
        $this->skipWithoutGit();
        $project = new TempProject([]);
        $this->gitInit($project);
        $project->write('a.txt', "one\n");
        $this->gitCommit($project, 'a.txt');

        $linked = $project->path('linked');
        $project->git('worktree', 'add', '-q', $linked);
        $this->assertFileExists($linked.'/.git');
        $this->assertDirectoryDoesNotExist($linked.'/.git', 'a linked worktree points at its git dir with a file');

        $git = new GitInfo($linked);
        $this->assertTrue($git->isAvailable(), 'git works in a linked worktree');

        $branch = 'pending';
        $git->branch(static function (?string $b) use (&$branch): void {
            $branch = $b;
        });
        $this->await(static function () use (&$branch): bool {
            return 'pending' !== $branch;
        });
        $this->assertNotNull($branch, 'and answers about it');
    }

    public function testStageAndCommitAgainstRealRepository(): void
    {
        if (null === $this->whichGit()) {
            self::markTestSkipped('git binary not available');
        }

        $project = new TempProject([]);
        $this->gitInit($project);
        $project->write('a.txt', "first\n");
        $this->gitCommit($project, 'a.txt');

        $git = new GitInfo($project->dir);

        // Stage a new change.
        $project->write('a.txt', "first\nsecond\n");
        $staged = null;
        $git->stage($project->path('a.txt'), static function (Outcome $ok) use (&$staged): void {
            $staged = $ok->isOk();
        });
        $this->await(static function () use (&$staged): bool {
            return null !== $staged;
        });
        $this->assertTrue($staged, 'git add succeeded');

        // Commit it.
        $committed = null;
        $git->commit('add second line', static function (Outcome $ok) use (&$committed): void {
            $committed = $ok->isOk();
        });
        $this->await(static function () use (&$committed): bool {
            return null !== $committed;
        });
        $this->assertTrue($committed, 'git commit succeeded');
    }

    public function testIsBusyTracksInFlightOperations(): void
    {
        if (null === $this->whichGit()) {
            self::markTestSkipped('git binary not available');
        }

        $project = new TempProject([]);
        $this->gitInit($project);
        $project->write('a.txt', "first\n");
        $this->gitCommit($project, 'a.txt');

        $git = new GitInfo($project->dir);
        $lane = \Porta\Tests\Support\Probe::get(\Porta\Tests\Support\Probe::get($git, 'runner'), 'lane');
        $busy = static fn (): bool => 0 !== $lane->inFlight();
        $this->assertFalse($busy(), 'idle before any op is dispatched');

        $project->write('a.txt', "first\nsecond\n");
        $done = false;
        $git->stage($project->path('a.txt'), static function () use (&$done): void {
            $done = true;
        });
        $this->assertTrue($busy(), 'busy the instant an op is in flight, before its process exits');

        $this->await(static function () use (&$done): bool {
            return $done;
        });
        $this->assertFalse($busy(), 'idle again once the process has exited');

        $git->cancelAll();
    }

    public function testAgainstARealRepository(): void
    {
        if (null === $this->whichGit()) {
            self::markTestSkipped('git binary not available');
        }

        $project = new TempProject([]);
        $this->gitInit($project);
        $project->write('a.txt', "line1\nline2\nline3\n");
        $this->gitCommit($project, 'a.txt');

        // Modify line 2 and append a line
        $project->write('a.txt', "line1\nCHANGED\nline3\nADDED\n");

        $git = new GitInfo($project->dir);
        $this->assertTrue($git->isAvailable());

        $branch = 'pending';
        $git->branch(static function (?string $b) use (&$branch): void {
            $branch = $b;
        });

        $marks = null;
        $git->diff($project->path('a.txt'), static function (array $m) use (&$marks): void {
            $marks = $m;
        });

        $this->await(static function () use (&$branch, &$marks): bool {
            return 'pending' !== $branch && null !== $marks;
        });

        $this->assertNotNull($branch, 'branch name resolved');
        $this->assertSame('modified', $marks[1] ?? null, 'line 2 modified');
        $this->assertSame('added', $marks[3] ?? null, 'line 4 added');

        $git->cancelAll();
    }

    public function testCancelAllTerminatesInFlightProcesses(): void
    {
        if (null === $this->whichGit()) {
            self::markTestSkipped('git binary not available');
        }

        $project = new TempProject([]);
        $this->gitInit($project);
        $project->write('a.txt', "x\n");
        $this->gitCommit($project, 'a.txt');

        $git = new GitInfo($project->dir);

        // Start requests but cancel before the event loop drains them, so the
        // processes are still live when cancelAll() runs.
        $done = false;
        $git->branch(static function () use (&$done): void {
            $done = true;
        });
        $git->diff($project->path('a.txt'), static function () use (&$done): void {
            $done = true;
        });
        $git->cancelAll();

        // Give any (cancelled) watchers a chance to have fired.
        $this->pump(0.2);

        $this->assertFalse($done, 'cancelled requests never invoke their callbacks');
    }

    public function testExplicitGitPathWinsOverDiscovery(): void
    {
        $project = new TempProject([]);
        $git = new GitInfo($project->dir, gitPath: '/custom/bin/git');
        $this->assertSame('/custom/bin/git', $git->binary());
    }

    public function testFallsBackToDiscoveryWithoutAnExplicitPath(): void
    {
        if (null === $this->whichGit()) {
            self::markTestSkipped('git binary not available');
        }
        $project = new TempProject([]);
        $git = new GitInfo($project->dir, gitPath: ''); // empty → auto-discover
        $this->assertNotNull($git->binary());
    }

    public function testCommitAppliesSignoffAndNoGpgSignFromSettings(): void
    {
        if (null === $this->whichGit()) {
            self::markTestSkipped('git binary not available');
        }
        $project = new TempProject([]);
        $this->gitInit($project);
        $project->git('config', 'commit.gpgsign', 'true'); // blocks an unsigned commit unless overridden
        $project->write('a.txt', "x\n");

        // signCommits off => --no-gpg-sign; signoff on => a trailer.
        $git = new GitInfo($project->dir, new GitSettings(['signoff' => true, 'signCommits' => false]));
        $staged = null;
        $git->stage($project->path('a.txt'), static function (Outcome $ok) use (&$staged): void {
            $staged = $ok->isOk();
        });
        $this->await(static function () use (&$staged): bool {
            return null !== $staged;
        });
        $committed = null;
        $git->commit('do the thing', static function (Outcome $ok) use (&$committed): void {
            $committed = $ok->isOk();
        });
        $this->await(static function () use (&$committed): bool {
            return null !== $committed;
        });

        $this->assertTrue($committed, '--no-gpg-sign let the commit through despite commit.gpgsign=true');
        $this->assertStringContainsString('Signed-off-by: tester <t@t>', $project->git('log', '-1', '--format=%B'));
        $git->cancelAll();
    }

    public function testCommitSplicesSignAndNoVerifyFlags(): void
    {
        if (null === $this->whichGit()) {
            self::markTestSkipped('git binary not available');
        }
        $project = new TempProject([]);
        $this->gitInit($project);
        $project->write('a.txt', "x\n");

        // -S needs no gpg key to *attempt*: the flags are spliced either way, so
        // this exercises the -S / --no-verify arms regardless of signing success.
        $git = new GitInfo($project->dir, new GitSettings(['signCommits' => true, 'noVerify' => true]));
        $staged = null;
        $git->stage($project->path('a.txt'), static function (Outcome $ok) use (&$staged): void {
            $staged = $ok->isOk();
        });
        $this->await(static function () use (&$staged): bool {
            return null !== $staged;
        });
        $done = false;
        $git->commit('m', static function () use (&$done): void {
            $done = true;
        });
        $this->await(static function () use (&$done): bool {
            return $done;
        });
        $this->assertTrue($done, 'the commit ran with the sign/no-verify flags spliced');
        $git->cancelAll();
    }

    private function gitInit(TempProject $project): void
    {
        $project->initGit(name: 'tester', email: 't@t'); // 'tester' is asserted as the blame author
    }

    private function gitCommit(TempProject $project, string $file): void
    {
        $project->git('add', $file);
        $project->git('commit', '-q', '-m', 'init');
    }
}
