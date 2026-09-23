<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Command\CommitMode;
use Porta\GitPlugin\Diff\WhitespaceMode;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\StagedState;
use Porta\Support\Outcome;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/**
 * Real-git integration tests for the changes-view plumbing (status, cached /
 * worktree / untracked diffs, unstage, discard). Skipped when git is absent.
 */
final class GitInfoStatusTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;

    public function testStatusListsStagedUnstagedAndUntracked(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "one\n"]);
        $project->write('a.txt', "one changed\n"); // unstaged edit
        $project->write('new.txt', "fresh\n"); // untracked
        $project->git('add', 'new.txt'); // staged add

        $entries = null;
        $raw = '';
        $git->status(static function (array $e, string $r) use (&$entries, &$raw): void {
            $entries = $e;
            $raw = $r;
        });
        $this->await(static function () use (&$entries): bool {
            return null !== $entries;
        });

        $byPath = [];
        foreach ($entries as $entry) {
            $byPath[$entry->path] = $entry;
        }
        $this->assertSame(StagedState::None, $byPath['a.txt']->stagedState());
        $this->assertSame('M', $byPath['a.txt']->displayStatus());
        $this->assertSame(StagedState::Full, $byPath['new.txt']->stagedState());
        $this->assertStringContainsString('a.txt', $raw, 'the raw porcelain output rides along');
    }

    public function testCachedAndWorktreeDiffsSplitTheChange(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "one\ntwo\n"]);
        $project->write('a.txt', "one staged\ntwo\n");
        $project->git('add', 'a.txt');
        $project->write('a.txt', "one staged\ntwo worktree\n"); // further unstaged edit

        $cached = null;
        $worktree = null;
        $git->cachedFileDiff($project->path('a.txt'), static function (string $out) use (&$cached): void {
            $cached = $out;
        });
        $git->worktreeFileDiff($project->path('a.txt'), static function (string $out) use (&$worktree): void {
            $worktree = $out;
        });
        $this->await(static function () use (&$cached, &$worktree): bool {
            return null !== $cached && null !== $worktree;
        });

        $this->assertStringContainsString('+one staged', $cached);
        $this->assertStringNotContainsString('worktree', $cached);
        $this->assertStringContainsString('+two worktree', $worktree);
        $this->assertStringNotContainsString('+one staged', $worktree);
    }

    public function testDiffContextControlsTheSurroundingLines(): void
    {
        $body = \implode("\n", \array_map(static fn (int $i): string => 'line '.$i, \range(1, end: 30)))."\n";
        $git = $this->repo($project, committed: ['a.txt' => $body]);
        $project->write('a.txt', \str_replace('line 15', replace: 'line 15 EDITED', subject: $body));

        $default = null;
        $ten = null;
        $git->worktreeFileDiff($project->path('a.txt'), static function (string $out) use (&$default): void {
            $default = $out;
        });
        $git->worktreeFileDiff(
            $project->path('a.txt'),
            static function (string $out) use (&$ten): void {
                $ten = $out;
            },
            context: 10,
        );
        $this->await(static function () use (&$default, &$ten): bool {
            return null !== $default && null !== $ten;
        });

        $this->assertStringContainsString(" line 12\n", $default, 'git default: three lines of context');
        $this->assertStringNotContainsString(" line 5\n", $default);
        $this->assertStringContainsString(" line 5\n", $ten, '-U10 reaches ten lines out');
        $this->assertStringNotContainsString(" line 1\n", $ten);

        $project->git('add', 'a.txt');
        $full = null;
        $git->cachedFileDiff(
            $project->path('a.txt'),
            static function (string $out) use (&$full): void {
                $full = $out;
            },
            context: 999_999,
        );
        $this->await(static function () use (&$full): bool {
            return null !== $full;
        });
        $this->assertStringContainsString(" line 1\n", $full, 'the huge -U shows the whole file');
        $this->assertStringContainsString(" line 30\n", $full);
    }

    public function testIgnoreWhitespaceDropsIndentOnlyChanges(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "alpha\nbravo\ncharlie\ndelta\necho\nfoxtrot\ngolf\nhotel\n"]);
        // One indent-only change (bravo) and one real change (hotel).
        $project->write('a.txt', "alpha\n  bravo\ncharlie\ndelta\necho\nfoxtrot\ngolf\nhotel CHANGED\n");

        $plain = null;
        $ignoring = null;
        $git->worktreeFileDiff($project->path('a.txt'), static function (string $out) use (&$plain): void {
            $plain = $out;
        });
        $git->worktreeFileDiff(
            $project->path('a.txt'),
            static function (string $out) use (&$ignoring): void {
                $ignoring = $out;
            },
            whitespace: WhitespaceMode::Hidden,
        );
        $this->await(static function () use (&$ignoring, &$plain): bool {
            return null !== $plain && null !== $ignoring;
        });

        $this->assertStringContainsString('+  bravo', $plain, 'the plain diff shows the indent change');
        $this->assertStringNotContainsString('bravo', $ignoring, '-w drops the whitespace-only hunk');
        $this->assertStringContainsString('+hotel CHANGED', $ignoring, 'real changes survive -w');

        $project->git('add', 'a.txt');
        $project->git('commit', '-q', '-m', 'ws');
        $sha = $project->git('log', '-1', '--format=%H');

        $cached = null;
        $show = null;
        $project->write('a.txt', "alpha\n    bravo\ncharlie\ndelta\necho\nfoxtrot\ngolf\nhotel CHANGED\n");
        $project->git('add', 'a.txt');
        $git->cachedFileDiff(
            $project->path('a.txt'),
            static function (string $out) use (&$cached): void {
                $cached = $out;
            },
            whitespace: WhitespaceMode::Hidden,
        );
        $git->commitFileDiff(
            $sha,
            'a.txt',
            static function (string $out) use (&$show): void {
                $show = $out;
            },
            whitespace: WhitespaceMode::Hidden,
        );
        $this->await(static function () use (&$cached, &$show): bool {
            return null !== $cached && null !== $show;
        });

        $this->assertStringNotContainsString('bravo', $cached, 'the cached -w diff hides the reindent');
        $this->assertStringNotContainsString('bravo', $show, "the commit's -w patch hides its indent hunk");
        $this->assertStringContainsString('+hotel CHANGED', $show);
    }

    public function testUntrackedFileDiffShowsEverythingAsAdditions(): void
    {
        $git = $this->repo($project, committed: ['seed.txt' => "s\n"]);
        $project->write('brand-new.txt', "alpha\nbeta\n");

        $diff = null;
        $git->untrackedFileDiff($project->path('brand-new.txt'), static function (string $out) use (&$diff): void {
            $diff = $out;
        });
        $this->await(static function () use (&$diff): bool {
            return null !== $diff;
        });

        $this->assertStringContainsString('+alpha', $diff);
        $this->assertStringContainsString('+beta', $diff);
    }

    public function testUnstageAndDiscardRoundTrip(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "one\n"]);
        $project->write('a.txt', "one changed\n");
        $project->git('add', 'a.txt');

        $unstaged = null;
        $git->unstageFile($project->path('a.txt'), static function (Outcome $ok) use (&$unstaged): void {
            $unstaged = $ok->isOk();
        });
        $this->await(static function () use (&$unstaged): bool {
            return null !== $unstaged;
        });
        $this->assertTrue($unstaged);
        // Not $project->git(): its trim() would strip the leading porcelain
        // status column (' M' — worktree-modified), which is load-bearing here.
        \exec('git -C '.\escapeshellarg($project->dir).' status --porcelain 2>/dev/null', $lines);
        $this->assertSame(' M a.txt', $lines[0], 'the edit moved back to the worktree side');

        $discarded = null;
        $git->discardFile($project->path('a.txt'), static function (Outcome $ok) use (&$discarded): void {
            $discarded = $ok->isOk();
        });
        $this->await(static function () use (&$discarded): bool {
            return null !== $discarded;
        });
        $this->assertTrue($discarded);
        $this->assertSame("one\n", \file_get_contents($project->path('a.txt')), 'worktree restored to HEAD');
    }

    /**
     * Stage everything, untracked included, then unstage it all — and on a
     * branch with no commit yet, where `reset` has no HEAD to go back to and
     * empties the index instead: the one command covers both.
     */
    public function testStageAllAndUnstageAllRoundTrip(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "one\n"]);
        $project->write('a.txt', "one changed\n");
        $project->write('new.txt', "fresh\n");
        $porcelain = static function () use ($project): array {
            $lines = [];
            \exec('git -C '.\escapeshellarg($project->dir).' status --porcelain 2>/dev/null', $lines);

            return $lines;
        };
        $run = function (\Closure $call): bool {
            $done = null;
            $call(static function (Outcome $ok) use (&$done): void {
                $done = $ok->isOk();
            });
            $this->await(static function () use (&$done): bool {
                return null !== $done;
            });

            return (bool) $done;
        };

        $this->assertTrue($run($git->stageAll(...)));
        $this->assertSame(['M  a.txt', 'A  new.txt'], $porcelain(), 'every change staged, the untracked file too');

        $this->assertTrue($run($git->unstageAll(...)));
        $this->assertSame([' M a.txt', '?? new.txt'], $porcelain(), 'and all of it back');

        $project->git('update-ref', '-d', 'HEAD'); // a branch with no commit
        $this->assertTrue($run($git->stageAll(...)));
        $this->assertTrue($run($git->unstageAll(...)), 'no HEAD to reset to: reset empties the index');
        $this->assertSame(['?? a.txt', '?? new.txt'], $porcelain());
    }

    public function testAmendRewritesTheLastCommitAndPrefillReadsIt(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "one\n"]);
        $project->write('a.txt', "one more\n");
        $project->git('add', 'a.txt');

        $committed = null;
        $git->commit("second\n\nwith body", static function (Outcome $ok) use (&$committed): void {
            $committed = $ok->isOk();
        });
        $this->await(static function () use (&$committed): bool {
            return null !== $committed;
        });
        $this->assertTrue($committed);

        $message = null;
        $git->lastCommitMessage(static function (string $m) use (&$message): void {
            $message = $m;
        });
        $this->await(static function () use (&$message): bool {
            return null !== $message;
        });
        $this->assertSame("second\n\nwith body", $message, 'the full message comes back for the amend prefill');

        $amended = null;
        $git->commit(
            'second, reworded',
            static function (Outcome $ok) use (&$amended): void {
                $amended = $ok->isOk();
            },
            CommitMode::Amend,
        );
        $this->await(static function () use (&$amended): bool {
            return null !== $amended;
        });
        $this->assertTrue($amended, 'the amend succeeded (a false here is git itself failing — lock contention, not a race)');
        $subjects = \explode("\n", $project->git('log', '--format=%s'));
        $this->assertSame(['second, reworded', 'init'], $subjects, 'amend rewrote the tip without adding a commit');
    }

    public function testUndoLastCommitSoftResetsAndRefusesTheRootCommit(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "one\n"]);
        $project->write('a.txt', "two\n");
        $project->git('add', 'a.txt');
        $project->git('commit', '-q', '-m', 'second');

        $undone = null;
        $git->undoLastCommit(static function (Outcome $ok) use (&$undone): void {
            $undone = $ok->isOk();
        });
        $this->await(static function () use (&$undone): bool {
            return null !== $undone;
        });
        $this->assertTrue($undone);
        $this->assertSame(['M  a.txt'], \explode("\n", $project->git('status', '--porcelain')), 'the undone commit\'s change is back and still staged');

        $refused = null;
        $git->undoLastCommit(static function (Outcome $ok) use (&$refused): void {
            $refused = $ok->isOk();
        });
        $this->await(static function () use (&$refused): bool {
            return null !== $refused;
        });
        $this->assertFalse($refused, 'the root commit has no parent to reset onto');
    }

    public function testResetToSoftKeepsTheIndexAndMixedUnstagesIt(): void
    {
        $git = $this->repo($project, committed: ['a.txt' => "one\n"]);
        $base = \trim($project->git('rev-parse', 'HEAD'));
        $project->write('a.txt', "one\nmore\n");
        $project->git('commit', '-am', 'more');

        $ok = null;
        $git->resetTo($base, 'soft', static function (Outcome $o) use (&$ok): void {
            $ok = $o->isOk();
        });
        $this->await(static function () use (&$ok): bool {
            return null !== $ok;
        });
        $this->assertTrue($ok);
        $this->assertSame($base, \trim($project->git('rev-parse', 'HEAD')));
        $this->assertSame(['M  a.txt'], \explode("\n", $project->git('status', '--porcelain')), 'soft leaves the change staged');

        $project->git('commit', '-am', 'more again');
        $ok = null;
        $git->resetTo($base, 'mixed', static function (Outcome $o) use (&$ok): void {
            $ok = $o->isOk();
        });
        $this->await(static function () use (&$ok): bool {
            return null !== $ok;
        });
        $this->assertTrue($ok);
        $this->assertSame($base, \trim($project->git('rev-parse', 'HEAD')));
        $this->assertSame(
            ['M a.txt'],
            \explode("\n", $project->git('status', '--porcelain')),
            'mixed unstages the change but keeps it in the working tree (the leading index-status space is trimmed by the helper)',
        );
    }

    public function testEveryNewVerbShortCircuitsWithoutARepo(): void
    {
        $project = new TempProject(['a.txt' => "x\n"]);
        $git = new GitInfo($project->dir);
        $this->assertFalse($git->isAvailable());

        $calls = [];
        $git->status(static function (array $e, string $r) use (&$calls): void {
            $calls[] = ['status', $e, $r];
        });
        $git->cachedFileDiff($project->path('a.txt'), static function (string $out) use (&$calls): void {
            $calls[] = ['cached', $out];
        });
        $git->worktreeFileDiff($project->path('a.txt'), static function (string $out) use (&$calls): void {
            $calls[] = ['worktree', $out];
        });
        $git->untrackedFileDiff($project->path('a.txt'), static function (string $out) use (&$calls): void {
            $calls[] = ['untracked', $out];
        });
        $git->unstageFile($project->path('a.txt'), static function (Outcome $ok) use (&$calls): void {
            $calls[] = ['unstage', $ok->isOk()];
        });
        $git->discardFile($project->path('a.txt'), static function (Outcome $ok) use (&$calls): void {
            $calls[] = ['discard', $ok->isOk()];
        });
        $git->applyCached("diff --git a/x b/x\n", static function (Outcome $ok) use (&$calls): void {
            $calls[] = ['apply', $ok->isOk()];
        });
        $git->log(10, static function (array $commits) use (&$calls): void {
            $calls[] = ['log', $commits];
        });
        $git->branches(static function (array $branches) use (&$calls): void {
            $calls[] = ['branches', $branches];
        });
        $git->checkout('main', static function (Outcome $ok, string $error) use (&$calls): void {
            $calls[] = ['checkout', $ok->isOk(), $error];
        });
        $git->commitFiles('deadbeef', static function (array $files) use (&$calls): void {
            $calls[] = ['files', $files];
        });
        $git->commitFileDiff('deadbeef', 'a.txt', static function (string $raw) use (&$calls): void {
            $calls[] = ['show', $raw];
        });
        $git->commit('msg', static function (Outcome $ok, string $out) use (&$calls): void {
            $calls[] = ['commit', $ok->isOk(), $out];
        });
        $git->lastCommitMessage(static function (string $m) use (&$calls): void {
            $calls[] = ['last', $m];
        });
        $git->undoLastCommit(static function (Outcome $ok) use (&$calls): void {
            $calls[] = ['undo', $ok->isOk()];
        });

        $this->assertSame(
            [
                ['status', [], ''],
                ['cached', ''],
                ['worktree', ''],
                ['untracked', ''],
                ['unstage', false],
                ['discard', false],
                ['apply', false],
                ['log', []],
                ['branches', []],
                ['checkout', false, 'git is not available'],
                ['files', []],
                ['show', ''],
                ['commit', false, ''],
                ['last', ''],
                ['undo', false],
            ],
            $calls,
            'all resolve synchronously with empty/false results',
        );
    }

    /** A GitInfo on a fresh repo with $committed files committed; skips without git. */
    private function repo(?TempProject &$project, array $committed): GitInfo
    {
        $this->skipWithoutGit();
        $project = new TempProject($committed);
        $project->initGit(name: 'tester', email: 't@t'); // 'tester' is asserted as the log author
        $project->commitAll('init');

        return new GitInfo($project->dir);
    }

    public function testBranchListSwitchAndCreate(): void
    {
        $git = $this->repo($project, ['a.txt' => "x\n"]);
        $project->git('branch', 'feature');

        $results = [];
        $git->branches(static function (array $branches) use (&$results): void {
            $results['list'] = $branches;
        });
        $this->await(static function () use (&$results): bool {
            return \array_key_exists('list', $results);
        });
        $names = \array_column($results['list'], 'name');
        $this->assertContains('feature', $names);
        $current = \array_values(\array_filter($results['list'], static fn (array $b): bool => $b['current']));
        $this->assertCount(1, $current, 'exactly one branch is current');

        $git->checkout('feature', static function (Outcome $ok, string $error) use (&$results): void {
            $results['checkout'] = $ok->isOk();
        });
        $this->await(static function () use (&$results): bool {
            return \array_key_exists('checkout', $results);
        });
        $this->assertTrue($results['checkout']);
        $this->assertSame('feature', $project->git('rev-parse', '--abbrev-ref', 'HEAD'));

        $git->createBranch('fresh', static function (Outcome $ok, string $error) use (&$results): void {
            $results['create'] = $ok->isOk();
        });
        $this->await(static function () use (&$results): bool {
            return \array_key_exists('create', $results);
        });
        $this->assertTrue($results['create']);

        // Duplicate creation fails with git's message.
        $git->createBranch('feature', static function (Outcome $ok, string $error) use (&$results): void {
            $results['dup'] = ['ok' => $ok->isOk(), 'error' => $error];
        });
        $this->await(static function () use (&$results): bool {
            return \array_key_exists('dup', $results);
        });
        $this->assertFalse($results['dup']['ok']);
        $this->assertNotSame('', $results['dup']['error']);
    }

    public function testDirtyWorkingTreeCheckoutFailsWithGitsMessage(): void
    {
        $git = $this->repo($project, ['a.txt' => "x\n"]);
        $project->git('branch', 'other');
        // Diverge the branches on the same file, then dirty the worktree so
        // the checkout must refuse.
        $project->git('checkout', '-q', 'other');
        \file_put_contents($project->path('a.txt'), data: "theirs\n");
        $project->git('commit', '-a', '-q', '-m', 'theirs');
        $project->git('checkout', '-q', '-');
        \file_put_contents($project->path('a.txt'), data: "dirty\n");

        $result = null;
        $git->checkout('other', static function (Outcome $ok, string $error) use (&$result): void {
            $result = ['ok' => $ok->isOk(), 'error' => $error];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertFalse($result['ok']);
        $this->assertNotSame('', $result['error'], "git's refusal reaches the caller verbatim");
    }

    public function testDeleteBranchMergedAndUnmerged(): void
    {
        $git = $this->repo($project, ['a.txt' => "x\n"]);
        $project->git('branch', 'merged');
        $project->git('checkout', '-q', '-b', 'unmerged');
        \file_put_contents($project->path('a.txt'), data: "diverge\n", flags: \FILE_APPEND);
        $project->git('commit', '-a', '-q', '-m', 'diverge');
        $project->git('checkout', '-q', '-');

        $results = [];
        $git->deleteBranch('merged', static function (Outcome $ok, string $error) use (&$results): void {
            $results['merged'] = $ok->isOk();
        });
        $this->await(static function () use (&$results): bool {
            return \array_key_exists('merged', $results);
        });
        $this->assertTrue($results['merged']);

        $git->deleteBranch('unmerged', static function (Outcome $ok, string $error) use (&$results): void {
            $results['unmerged'] = ['ok' => $ok->isOk(), 'error' => $error];
        });
        $this->await(static function () use (&$results): bool {
            return \array_key_exists('unmerged', $results);
        });
        $this->assertFalse($results['unmerged']['ok'], '-d refuses an unmerged branch');
        $this->assertNotSame('', $results['unmerged']['error'], "git's refusal (which names -D) reaches the caller");
    }

    public function testCheckoutTrackingCreatesALocalTrackingBranch(): void
    {
        $git = $this->repo($project, ['a.txt' => "x\n"]);
        // A local bare remote with a branch the local repo does not have.
        $project->git('checkout', '-q', '-b', 'feature');
        $project->git('checkout', '-q', '-');
        $project->git('init', '-q', '--bare', 'origin.git');
        $project->git('remote', 'add', 'origin', $project->path('origin.git'));
        $project->git('push', '-q', 'origin', 'feature');
        $project->git('branch', '-q', '-D', 'feature');
        $project->git('fetch', '-q', 'origin');

        $result = null;
        $git->checkoutTracking('origin/feature', static function (Outcome $ok, string $error) use (&$result): void {
            $result = ['ok' => $ok->isOk(), 'error' => $error];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame('origin/feature', $project->git('rev-parse', '--abbrev-ref', 'feature@{upstream}'), 'the new local branch tracks the remote');
    }

    public function testLogSkipReturnsTheOlderPage(): void
    {
        $git = $this->repo($project, ['a.txt' => "x\n"]);
        $project->git('commit', '-q', '--allow-empty', '-m', 'second');
        $project->git('commit', '-q', '--allow-empty', '-m', 'third');

        $pages = [];
        $git->log(2, static function (array $commits) use (&$pages): void {
            $pages[0] = \array_column($commits, 'subject');
        });
        $git->log(
            2,
            static function (array $commits) use (&$pages): void {
                $pages[1] = \array_column($commits, 'subject');
            },
            skip: 2,
        );

        $this->await(static function () use (&$pages): bool {
            return 2 === \count($pages);
        });
        $this->assertSame(['third', 'second'], $pages[0]);
        $this->assertSame(['init'], $pages[1], '--skip lands on the older page');
    }

    public function testStashPushPopApplyDropAndList(): void
    {
        $git = $this->repo($project, ['a.txt' => "one\n"]);

        // A clean tree: push succeeds with git's "no local changes" line.
        $result = null;
        $git->stashPush(static function (Outcome $ok, string $line) use (&$result): void {
            $result = [$ok->isOk(), $line];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertTrue($result[0]);
        $this->assertStringContainsString('No local changes', $result[1]);

        // A dirty tree stashes; the list carries the subject; pop restores.
        \file_put_contents($project->path('a.txt'), data: "two\n");
        $result = null;
        $git->stashPush(static function (Outcome $ok, string $line) use (&$result): void {
            $result = [$ok->isOk(), $line];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertTrue($result[0]);
        $this->assertStringContainsString('Saved working directory', $result[1]);
        $this->assertSame("one\n", \file_get_contents($project->path('a.txt')), 'the tree reverted');

        $stashes = null;
        $git->stashList(static function (array $list) use (&$stashes): void {
            $stashes = $list;
        });
        $this->await(static function () use (&$stashes): bool {
            return null !== $stashes;
        });
        $this->assertCount(1, $stashes);
        $this->assertSame('stash@{0}', $stashes[0]['ref']);
        $this->assertStringContainsString('WIP', $stashes[0]['subject']);
        $this->assertGreaterThan(0, $stashes[0]['timestamp']);

        // Apply keeps the stash; drop removes it; a second drop fails.
        $result = null;
        $git->stashApply('stash@{0}', static function (Outcome $ok, string $error) use (&$result): void {
            $result = [$ok->isOk(), $error];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertTrue($result[0]);
        $this->assertSame("two\n", \file_get_contents($project->path('a.txt')), 'apply restored the change');

        $project->git('checkout', '-q', '--', 'a.txt'); // clean again so drop/pop paths are unambiguous
        $result = null;
        $git->stashDrop('stash@{0}', static function (Outcome $ok, string $error) use (&$result): void {
            $result = [$ok->isOk(), $error];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertTrue($result[0]);

        $result = null;
        $git->stashPop(null, static function (Outcome $ok, string $error) use (&$result): void {
            $result = [$ok->isOk(), $error];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertFalse($result[0], 'nothing left to pop');
        $this->assertNotSame('', $result[1], 'git explains');
    }

    public function testStashPopConflictSurfacesGitsError(): void
    {
        $git = $this->repo($project, ['a.txt' => "one\n"]);

        \file_put_contents($project->path('a.txt'), data: "stashed\n");
        $project->git('stash', 'push', '-q');
        \file_put_contents($project->path('a.txt'), data: "conflicting\n");
        $project->git('add', '-A');
        $project->git('commit', '-q', '-m', 'conflicting');

        $result = null;
        $git->stashPop(null, static function (Outcome $ok, string $error) use (&$result): void {
            $result = [$ok->isOk(), $error];
        });
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        });
        $this->assertFalse($result[0], 'the conflicting pop failed');
        // Git reports the conflict on stdout — stderr (what we surface) is
        // empty here; GitCommands supplies the fallback message.
        $this->assertStringContainsString('<<<<<<<', (string) \file_get_contents($project->path('a.txt')), 'the markers landed in the tree');
    }

    public function testStashVerbsWithoutARepository(): void
    {
        $this->skipWithoutGit();
        $plain = new TempProject();
        $git = new GitInfo($plain->dir);

        $calls = [];
        $git->stashPush(static function (Outcome $ok, string $line) use (&$calls): void {
            $calls['push'] = [$ok->isOk(), $line];
        });
        $git->stashList(static function (array $list) use (&$calls): void {
            $calls['list'] = $list;
        });
        $this->assertSame(['push' => [false, ''], 'list' => []], $calls, 'unavailable short-circuits synchronously');
    }

    public function testLogWithAPathFilterComposesWithSkip(): void
    {
        $git = $this->repo($project, ['a.txt' => "a\n", 'b.txt' => "b\n"]);
        \file_put_contents($project->path('a.txt'), data: "a2\n");
        $project->git('commit', '-a', '-q', '-m', 'touch-a-1');
        \file_put_contents($project->path('b.txt'), data: "b2\n");
        $project->git('commit', '-a', '-q', '-m', 'touch-b');
        \file_put_contents($project->path('a.txt'), data: "a3\n");
        $project->git('commit', '-a', '-q', '-m', 'touch-a-2');

        $commits = null;
        $git->log(
            10,
            static function (array $entries) use (&$commits): void {
                $commits = $entries;
            },
            path: 'a.txt',
        );
        $this->await(static function () use (&$commits): bool {
            return null !== $commits;
        });
        $this->assertSame(['touch-a-2', 'touch-a-1', 'init'], \array_map(static fn ($e): string => $e->subject, $commits), 'only commits touching a.txt');

        $commits = null;
        $git->log(
            1,
            static function (array $entries) use (&$commits): void {
                $commits = $entries;
            },
            skip: 1,
            path: 'a.txt',
        );
        $this->await(static function () use (&$commits): bool {
            return null !== $commits;
        });
        $this->assertSame(['touch-a-1'], \array_map(static fn ($e): string => $e->subject, $commits), 'skip pages within the filter');
    }
}
