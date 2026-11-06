<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\HunkParser;
use Porta\GitPlugin\DiffStat;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\Setting\GitSettings;
use Porta\GitPlugin\Tests\Support\Conflicts;
use Porta\Support\Outcome;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Probe;
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
            $this->markTestSkipped('git binary not available');
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

    /** A refused commit says why: a hook's words, git's own error — both on stderr, which the flash used to drop. */
    public function testARefusedCommitSaysWhy(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject([]);
        $this->gitInit($project);
        $project->write('a.txt', "first\n");
        $this->gitCommit($project, 'a.txt');
        $project->write('.git/hooks/pre-commit', "#!/bin/sh\necho 'checking style' >&2\necho 'lint failed: a.txt' >&2\nexit 1\n");
        \chmod($project->path('.git/hooks/pre-commit'), permissions: 0o755);
        $project->write('a.txt', "first\nsecond\n");
        $project->git('add', 'a.txt');
        $git = new GitInfo($project->dir);

        $said = null;
        $git->commit('add second line', static function (Outcome $ok, string $out) use (&$said): void {
            $said = $ok->isOk() ? 'committed' : $out;
        });
        $this->await(static function () use (&$said): bool {
            return null !== $said;
        });

        $this->assertSame('lint failed: a.txt', $said, "the hook's last word, where it was nothing");
    }

    /** Nothing staged: git says why on stdout, over several lines — the flash gets the one that says it. */
    public function testACommitOfNothingSaysSoInOneLine(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject([]);
        $this->gitInit($project);
        $project->write('a.txt', "first\n");
        $this->gitCommit($project, 'a.txt');
        $git = new GitInfo($project->dir);

        $said = null;
        $git->commit('nothing', static function (Outcome $ok, string $out) use (&$said): void {
            $said = $ok->isOk() ? 'committed' : $out;
        });
        $this->await(static function () use (&$said): bool {
            return null !== $said;
        });

        $this->assertSame('nothing to commit, working tree clean', $said);
    }

    /** A path is a file's name, not a pattern: discarding `[id].txt` must leave `i.txt` and `d.txt` alone. */
    public function testAPathWithBracketsIsReadLiterally(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['[id].txt' => "route\n", 'i.txt' => "i\n", 'd.txt' => "d\n"]);
        $this->gitInit($project);
        $project->git('add', '-A');
        $project->git('commit', '-q', '-m', 'init');
        foreach (['[id].txt', 'i.txt', 'd.txt'] as $file) {
            $project->write($file, "changed\n");
        }
        $git = new GitInfo($project->dir);

        $done = null;
        $git->discardFile($project->path('[id].txt'), static function (Outcome $ok) use (&$done): void {
            $done = $ok->isOk();
        });
        $this->await(static function () use (&$done): bool {
            return null !== $done;
        });

        $this->assertSame("route\n", \file_get_contents($project->path('[id].txt')));
        $this->assertSame("changed\n", \file_get_contents($project->path('i.txt')), 'not matched by the brackets');
        $this->assertSame("changed\n", \file_get_contents($project->path('d.txt')));
    }

    /**
     * Index writers take turns: two `git add`s at once raced for index.lock
     * and one was refused. The second starts only once the first is done —
     * and a read does not wait.
     */
    public function testIndexWritersRunOneAtATime(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['a.txt' => "a\n", 'b.txt' => "b\n"]);
        $this->gitInit($project);
        $git = new GitInfo($project->dir);
        $lane = Probe::object($git, 'runner', 'lane');

        $staged = [];
        foreach (['a.txt', 'b.txt'] as $file) {
            $git->stage($project->path($file), static function (Outcome $ok) use (&$staged, $file): void {
                $staged[$file] = $ok->isOk();
            });
        }
        $this->assertCount(1, Probe::get($lane, 'running'), 'the second add waits for the first');
        $git->branch(static function (): void {});
        $this->assertCount(2, Probe::get($lane, 'running'), 'a read does not wait');

        $this->await(static function () use (&$staged): bool {
            return 2 === \count($staged);
        });
        $this->assertSame(['a.txt' => true, 'b.txt' => true], $staged, 'both staged, neither refused');
    }

    public function testIsBusyTracksInFlightOperations(): void
    {
        if (null === $this->whichGit()) {
            $this->markTestSkipped('git binary not available');
        }

        $project = new TempProject([]);
        $this->gitInit($project);
        $project->write('a.txt', "first\n");
        $this->gitCommit($project, 'a.txt');

        $git = new GitInfo($project->dir);
        $lane = Probe::get(Probe::get($git, 'runner'), 'lane');
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
            $this->markTestSkipped('git binary not available');
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
            $this->markTestSkipped('git binary not available');
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
            $this->markTestSkipped('git binary not available');
        }
        $project = new TempProject([]);
        $git = new GitInfo($project->dir, gitPath: ''); // empty → auto-discover
        $this->assertNotNull($git->binary());
    }

    public function testCommitAppliesSignoffAndNoGpgSignFromSettings(): void
    {
        if (null === $this->whichGit()) {
            $this->markTestSkipped('git binary not available');
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
            $this->markTestSkipped('git binary not available');
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

    public function testADiffIgnoresTheExternalDriverGitHandsADifftool(): void
    {
        // Git runs a difftool from inside `git diff`, with GIT_EXTERNAL_DIFF
        // naming its helper — inherited by every git Porta spawns. Without
        // --no-ext-diff the helper got the diff and the comparison read as
        // "identical". `true` prints nothing, as the helper would here.
        $this->skipWithoutGit();
        $project = new TempProject(['left.txt' => "one\n", 'right.txt' => "two\n"]);
        $project->initGit(); // git runs a difftool from its work tree
        $git = new GitInfo($project->dir, new GitSettings(['path' => (string) $this->whichGit()]));
        $previous = \getenv('GIT_EXTERNAL_DIFF');
        \putenv('GIT_EXTERNAL_DIFF=true');
        try {
            $out = null;
            $git->compareFiles($project->path('left.txt'), $project->path('right.txt'), static function (string $raw) use (&$out): void {
                $out = $raw;
            });
            $this->await(static function () use (&$out): bool {
                return null !== $out;
            }, what: 'the comparison');
        } finally {
            \putenv(false === $previous ? 'GIT_EXTERNAL_DIFF' : 'GIT_EXTERNAL_DIFF='.$previous);
        }

        $this->assertStringContainsString("-one\n+two", (string) $out);
    }

    /** A commit's added and removed lines over all its files; a commit that only removes says 0 added. */
    public function testACommitsStatCountsItsLines(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject([]);
        $this->gitInit($project);
        $project->write('a.txt', "one\ntwo\n");
        $project->write('b.txt', "x\n");
        $this->gitCommit($project, 'a.txt');
        $project->git('add', 'b.txt');
        $project->git('commit', '-q', '-m', 'b');
        $project->write('a.txt', "one\nthree\nfour\n");
        $project->git('rm', '-q', 'b.txt');
        $project->git('commit', '-qam', 'mixed');
        $git = new GitInfo($project->dir);

        $this->assertEquals(new DiffStat(2, 2), $this->stat($git, 'HEAD'));
        $this->assertEquals(new DiffStat(1, 0), $this->stat($git, 'HEAD~1'));
        $this->assertNull($this->stat($git, 'no-such-commit'));
    }

    private function stat(GitInfo $git, string $sha): ?DiffStat
    {
        $stat = false;
        $git->commitStat($sha, static function (?DiffStat $answer) use (&$stat): void {
            $stat = $answer;
        });
        $this->await(static function () use (&$stat): bool {
            return false !== $stat;
        }, what: 'the stat');

        return false === $stat ? null : $stat;
    }

    public function testABranchsRemoteIsItsConfiguredOne(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject([]);
        $this->gitInit($project);
        $project->git('config', 'branch.main.remote', 'origin');
        $project->git('config', 'branch.local.remote', '.');
        $git = new GitInfo($project->dir);

        $answers = [];
        foreach (['main', 'unpublished', 'local'] as $branch) {
            $git->remoteOf($branch, static function (?string $remote) use (&$answers, $branch): void {
                $answers[$branch] = $remote;
            });
        }
        $this->await(static function () use (&$answers): bool {
            return 3 === \count($answers);
        }, what: 'every answer');

        \ksort($answers);
        $this->assertSame(['local' => null, 'main' => 'origin', 'unpublished' => null], $answers, 'a branch tracking a local one names no remote');
    }

    public function testAConflictedFilesDiffIsTakenAgainstOurSide(): void
    {
        // A plain `git diff` of an unmerged path is a combined diff the pane
        // cannot read; against ours, the markers and their side are additions.
        $this->skipWithoutGit();
        $project = new TempProject(['a.txt' => "base\n"]);
        Conflicts::inA($project);

        $diff = null;
        new GitInfo($project->dir)->conflictFileDiff(
            $project->path('a.txt'),
            static function (string $out) use (&$diff): void {
                $diff = $out;
            },
            context: 0,
        );
        $this->await(static function () use (&$diff): bool {
            return null !== $diff;
        }, what: 'the diff');

        $this->assertStringContainsString('+<<<<<<< HEAD', (string) $diff);
        $this->assertStringContainsString('+theirs', (string) $diff);
        $this->assertStringNotContainsString('@@@', (string) $diff, 'not the combined form');
    }
}
