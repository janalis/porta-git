<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\GitPlugin\Command\GitCommands;
use Porta\GitPlugin\Command\WorktreeCommands;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Process\ProcessLane;
use Porta\GitPlugin\Tests\Support\DrivesPickers;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\I18n\Translator;
use Porta\Support\Clipboard;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Tui;

/**
 * Drives every {@see GitCommands} action against a real git repository. These
 * used to be covered only through the full-Ide E2e flows
 * ({@see \Porta\Tests\E2e\IdeGitFlowsTest}), which fork real git and race the
 * await window under machine load — so the coverage of GitCommands' async
 * callback bodies came and went with the load. This exercises the same
 * callbacks from a focused fixture: the repo is built synchronously with
 * {@see Process} (no racing there), and each async git op is pumped to
 * completion on the loop before the assertion.
 */
final class GitCommandsTest extends TestCase
{
    use GitBinary;
    use DrivesPickers;

    private EventDispatcher $events;
    private TempProject $project;
    private Tui $tui;

    protected function setUp(): void
    {
        $this->skipWithoutGit();
    }

    // --- Simple async actions -------------------------------------------------

    public function testCommitPromptsThenCommitsTheStagedIndex(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\ntwo\n");
        $this->project->git('add', '-A');

        $commands->commit();
        $this->type('a fresh commit');
        $this->key("\r"); // submit the prompt
        $this->pumpUntil();

        $this->assertStringContainsString('Committed: a fresh commit', $this->statusRow($this->statusBar));
        $this->assertStringContainsString('a fresh commit', $this->project->git('log', '-1', '--pretty=%s'));
    }

    public function testCommitWithAnEmptyMessageDoesNothing(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $before = \trim($this->project->git('rev-parse', 'HEAD'));

        $commands->commit();
        $this->key("\r"); // submit with an empty message
        $this->drain(); // a non-event: nothing will flash, so there is nothing to await

        $this->assertSame($before, \trim($this->project->git('rev-parse', 'HEAD')), 'no commit was made');
        // HEAD alone proves nothing here — git refuses an empty message on its
        // own, so this passed even with the early return deleted. What the
        // guard actually buys is that git is never RUN: without it the user
        // gets "Commit failed (nothing staged?)" for having typed nothing.
        $this->assertSame('', \trim($this->statusRow($this->statusBar)), 'and git was never run, so nothing was flashed');
    }

    public function testCommitWithNoRepositoryReportsUnavailable(): void
    {
        $commands = $this->commandsOutsideRepo(['a.txt' => "one\n"]);

        $commands->commit();
        $this->pumpUntil();

        $this->assertStringContainsString('Git not available', $this->statusRow($this->statusBar));
        $this->assertFalse(EditorState::promptOpen($this->editor), 'refused: no message is asked for');
    }

    /**
     * Every action refuses outside a repository, through the same guard.
     *
     * @return iterable<string, array{callable(GitCommands): void}>
     */
    public static function outsideARepo(): iterable
    {
        yield 'stashes' => [static fn (GitCommands $c) => $c->stashes()];
        yield 'branchFromCommit' => [static fn (GitCommands $c) => $c->branchFromCommit(self::commitEntry('0000000000000000000000000000000000000000'))];
        yield 'cherryPick' => [static fn (GitCommands $c) => $c->cherryPick(self::commitEntry('0000000000000000000000000000000000000000'))];
        yield 'revertCommit' => [static fn (GitCommands $c) => $c->revertCommit(self::commitEntry('0000000000000000000000000000000000000000'))];
        yield 'resetToCommit' => [static fn (GitCommands $c) => $c->resetToCommit(self::commitEntry('0000000000000000000000000000000000000000'))];
        yield 'branches' => [static fn (GitCommands $c) => $c->branches()];
    }

    private static function commitEntry(string $sha): LogEntry
    {
        return new LogEntry($sha, \substr($sha, offset: 0, length: 7), 'Tester', 0, 'subject');
    }

    /** @param callable(GitCommands): void $action */
    #[DataProvider('outsideARepo')]
    public function testActionsRefuseOutsideARepository(callable $action): void
    {
        $commands = $this->commandsOutsideRepo(['a.txt' => "one\n"]);

        $action($commands);
        $this->pumpUntil();

        $this->assertStringContainsString('Git not available', $this->statusRow($this->statusBar));
        // The flash alone passed with the refusal falling through: the verb
        // flashed, then asked for its list or its name anyway.
        $this->assertFalse(EditorState::pickerOpen($this->editor), 'and nothing is offered after it');
        $this->assertFalse(EditorState::promptOpen($this->editor), 'or asked for');
    }

    public function testStashesFlashesWhenThereAreNone(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->stashes();
        $this->pumpUntil();

        $this->assertStringContainsString('No stashes', $this->statusRow($this->statusBar));
    }

    public function testStashesPicksAStashAndPopsIt(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\nstashed\n");
        $this->project->git('stash', 'push', '-m', 'wip');

        $commands->stashes();
        $this->overlayOpens(); // the stash list
        $this->key("\r"); // pick the only stash
        $this->key("\x1b[B"); // move to "Pop"
        $this->key("\r"); // choose Pop
        $this->pumpUntil();

        $this->assertStringContainsString('stashed', $this->readFile('a.txt'), 'the stash was popped back');
    }

    public function testStashesAppliesAStashKeepingItInTheList(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\napplied\n");
        $this->project->git('stash', 'push', '-m', 'wip');

        $commands->stashes();
        $this->overlayOpens();
        $this->key("\r"); // pick the stash
        $this->key("\r"); // "Apply" is the first action
        $this->pumpUntil();

        $this->assertStringContainsString('applied', $this->readFile('a.txt'), 'the stash was applied to the tree');
        $this->assertStringContainsString('wip', $this->project->git('stash', 'list'), 'apply keeps the stash');
    }

    public function testStashesDropsAStash(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\ndropme\n");
        $this->project->git('stash', 'push', '-m', 'wip');

        $commands->stashes();
        $this->overlayOpens();
        $this->key("\r"); // pick the stash
        $this->key("\x1b[B"); // Apply → Pop
        $this->key("\x1b[B"); // Pop → Drop
        $this->key("\r"); // choose Drop
        $this->pumpUntil();

        $this->assertStringContainsString('Dropped', $this->statusRow($this->statusBar));
        $this->assertStringNotContainsString('wip', $this->project->git('stash', 'list'), 'the stash is gone');
    }

    public function testStashesActionCanBeCancelled(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\nkeep\n");
        $this->project->git('stash', 'push', '-m', 'wip');

        $commands->stashes();
        $this->overlayOpens();
        $this->key("\r"); // pick the stash
        $this->key("\x1b[B"); // Apply → Pop
        $this->key("\x1b[B"); // Pop → Drop
        $this->key("\x1b[B"); // Drop → Cancel
        $this->key("\r"); // choose Cancel — a no-op
        $this->pumpUntil(fn (): bool => !EditorState::pickerOpen($this->editor));

        $this->assertStringContainsString('wip', $this->project->git('stash', 'list'), 'the stash is untouched');
    }

    // --- Commit-history context actions ---------------------------------------

    public function testCopyCommitHashCopiesToTheClipboardAndFlashes(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $sha = \trim($this->project->git('rev-parse', 'HEAD'));

        $commands->copyCommitHash(self::commitEntry($sha));

        $this->assertStringContainsString('Copied '.\substr($sha, offset: 0, length: 7), $this->statusRow($this->statusBar));
        $this->assertStringContainsString("\x1b]52;c;".\base64_encode($sha), $this->tui->getTerminal()->getOutput(), 'the full hash reached the OSC 52 clipboard');
    }

    public function testBranchFromCommitCreatesABranchAtThatCommit(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $first = \trim($this->project->git('rev-parse', 'HEAD'));
        $this->write('a.txt', "one\ntwo\n");
        $this->project->git('commit', '-am', 'second');

        $commands->branchFromCommit(self::commitEntry($first));
        $this->type('from-first');
        $this->key("\r");
        $this->pumpUntil();

        $this->assertStringContainsString('Created and switched to from-first', $this->statusRow($this->statusBar));
        $this->assertSame($first, \trim($this->project->git('rev-parse', 'from-first')), 'the new branch points at the chosen commit');
    }

    public function testBranchFromCommitWithAnEmptyNameDoesNothing(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $before = \trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD'));

        $commands->branchFromCommit(self::commitEntry(\trim($this->project->git('rev-parse', 'HEAD'))));
        $this->key("\r"); // submit an empty name — the prompt returns early
        $this->gitSettles();

        $this->assertSame($before, \trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD')), 'no branch created or switched');
        // git refuses an empty name on its own, so the branch alone passed
        // with the early return gone: what it buys is that git never ran.
        $this->assertSame('', \trim($this->statusRow($this->statusBar)), 'and nothing failed, because nothing was tried');
    }

    public function testCherryPickAppliesACommitOntoTheCurrentBranch(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->project->git('checkout', '-b', 'side');
        $this->write('b.txt', "new\n");
        $this->project->git('add', 'b.txt');
        $this->project->git('commit', '-m', 'add b');
        $pick = \trim($this->project->git('rev-parse', 'HEAD'));
        $this->project->git('checkout', '-'); // back to the starting branch — b.txt is gone

        $commands->cherryPick(self::commitEntry($pick));
        // Await the completion flash, not the on-disk effect: git writes b.txt
        // *before* its process exits, so under load a file-based pump can win
        // the race against the exit callback that sets the flash.
        $this->pumpUntil(fn (): bool => \str_contains($this->statusRow($this->statusBar), 'Cherry-picked'));

        $this->assertStringContainsString('Cherry-picked', $this->statusRow($this->statusBar));
        $this->assertFileExists($this->project->dir.'/b.txt');
    }

    public function testRevertCommitUndoesItWithANewCommit(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\nadded\n");
        $this->project->git('commit', '-am', 'add line');
        $bad = \trim($this->project->git('rev-parse', 'HEAD'));

        $commands->revertCommit(self::commitEntry($bad));
        // Await the completion flash — the working-tree rewrite precedes the
        // exit callback that sets it (see the cherry-pick test above).
        $this->pumpUntil(fn (): bool => \str_contains($this->statusRow($this->statusBar), 'Reverted'));

        $this->assertStringContainsString('Reverted', $this->statusRow($this->statusBar));
        $this->assertStringNotContainsString('added', $this->readFile('a.txt'), 'the reverting commit removed the line');
    }

    public function testResetToCommitSoftMovesHeadAndKeepsTheWorkingTree(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $base = \trim($this->project->git('rev-parse', 'HEAD'));
        $this->write('a.txt', "one\nmore\n");
        $this->project->git('commit', '-am', 'more');

        $commands->resetToCommit(self::commitEntry($base));
        $this->overlayOpens();
        $this->key("\r"); // "Soft" leads the confirm list
        $this->pumpUntil();

        $this->assertStringContainsString('Reset to '.\substr($base, offset: 0, length: 7).' (soft)', $this->statusRow($this->statusBar));
        $this->assertSame($base, \trim($this->project->git('rev-parse', 'HEAD')), 'HEAD moved to the chosen commit');
        $this->assertStringContainsString('more', $this->readFile('a.txt'), 'a soft reset leaves the working tree untouched');
    }

    public function testResetToCommitMixedUnstagesTheDiffButKeepsTheWorkingTree(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $base = \trim($this->project->git('rev-parse', 'HEAD'));
        $this->write('a.txt', "one\nmore\n");
        $this->project->git('commit', '-am', 'more');

        $commands->resetToCommit(self::commitEntry($base));
        $this->overlayOpens();
        $this->key("\x1b[B"); // Soft → Mixed
        $this->key("\r");
        $this->pumpUntil();

        $this->assertStringContainsString('Reset to '.\substr($base, offset: 0, length: 7).' (mixed)', $this->statusRow($this->statusBar));
        $this->assertSame($base, \trim($this->project->git('rev-parse', 'HEAD')), 'HEAD moved to the chosen commit');
        $this->assertStringContainsString('more', $this->readFile('a.txt'), 'a mixed reset leaves the working tree untouched');
        $this->assertSame(['M a.txt'], \explode("\n", $this->porcelain()), 'unstaging is the whole point of mixed vs. soft — the diff must show as unstaged, not staged');
    }

    public function testResetToCommitCancelLeavesHeadAlone(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\nmore\n");
        $this->project->git('commit', '-am', 'more');
        $tip = \trim($this->project->git('rev-parse', 'HEAD'));

        $commands->resetToCommit(self::commitEntry($tip));
        $this->overlayOpens();
        $this->key("\x1b[B"); // → Mixed
        $this->key("\x1b[B"); // → Cancel
        $this->key("\r"); // pick Cancel: onPick returns without touching git
        $this->gitSettles();

        $this->assertSame($tip, \trim($this->project->git('rev-parse', 'HEAD')), 'cancelling changes nothing');
        $this->assertSame('', \trim($this->statusRow($this->statusBar)), 'git was never asked for a "cancel" reset, so nothing failed');
    }

    public function testFileHistoryWithNoOpenFileReportsUnavailable(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->fileHistory();
        $this->pumpUntil();

        $this->assertStringContainsString('Git not available', $this->statusRow($this->statusBar));
    }

    public function testFileHistoryWithAnOpenFileIsAcceptedWhenNoGitViewIsAttached(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->openInEditor('a.txt', "one\n");

        // No view is registered in this rig, so `openView('git')` is a no-op —
        // but the verb must reach that branch without flashing "unavailable".
        $commands->fileHistory();
        $this->drain(); // likewise: the assertion below is that NOTHING flashed

        $this->assertStringNotContainsString('Git not available', $this->statusRow($this->statusBar));
    }

    // --- The branch picker ----------------------------------------------------

    public function testBranchesCreatesAndSwitchesToANewBranch(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->branches();
        $this->overlayOpens(); // the branch list
        $this->key("\r"); // "+ Create new branch…" leads the list
        $this->type('feature');
        $this->key("\r"); // submit the name
        $this->pumpUntil();

        $this->assertStringContainsString('Created and switched to feature', $this->statusRow($this->statusBar));
        $this->assertSame('feature', \trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD')));
    }

    public function testNewBranchOpensTheCreatePromptDirectly(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->newBranch(); // the status-bar branch menu's "New Branch…"
        $this->type('feature');
        $this->key("\r");
        $this->pumpUntil();

        $this->assertStringContainsString('Created and switched to feature', $this->statusRow($this->statusBar));
        $this->assertSame('feature', \trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD')));
    }

    public function testDeleteBranchOpensThePickerDirectly(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->project->git('branch', 'stale');

        $commands->deleteBranch(); // the status-bar branch menu's "Delete Branch…"
        $this->overlayOpens(); // the deletable list
        $this->key("\r"); // pick "stale" (the only deletable)
        $this->key("\r"); // confirm "Delete"
        $this->pumpUntil();

        $this->assertStringContainsString('Deleted branch stale', $this->statusRow($this->statusBar));
    }

    public function testBranchActionsReportUnavailableOutsideARepo(): void
    {
        $this->commandsOutsideRepo(['a.txt' => "one\n"])->newBranch();
        $this->assertStringContainsString('Git not available', $this->statusRow($this->statusBar));
        $this->assertFalse(EditorState::promptOpen($this->editor), 'no name is asked for');

        $this->commandsOutsideRepo(['a.txt' => "one\n"])->deleteBranch();
        $this->assertStringContainsString('Git not available', $this->statusRow($this->statusBar));
    }

    public function testBranchesSwitchesToAnExistingBranch(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->project->git('branch', 'other');

        $commands->branches();
        $this->overlayOpens();
        // Rows: [+ Create], [− Delete], [+ New worktree], main (current), other. Move to "other".
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\r");
        $this->pumpUntil();

        $this->assertStringContainsString('Switched to other', $this->statusRow($this->statusBar));
        $this->assertSame('other', \trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD')));
    }

    public function testBranchesDeletesABranchThroughTheConfirm(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->project->git('branch', 'stale');

        $commands->branches();
        $this->overlayOpens();
        $this->key("\x1b[B"); // move to "− Delete branch…"
        $this->key("\r");
        $this->overlayOpens(); // the deletable list
        $this->key("\r"); // pick "stale" (the only deletable)
        $this->key("\r"); // confirm "Delete"
        $this->pumpUntil();

        $this->assertStringContainsString('Deleted branch stale', $this->statusRow($this->statusBar));
        $this->assertStringNotContainsString('stale', $this->project->git('branch'));
    }

    public function testBranchesDeleteCanBeCancelledAtTheConfirm(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->project->git('branch', 'keep');

        $commands->branches();
        $this->overlayOpens();
        $this->key("\x1b[B"); // "− Delete branch…"
        $this->key("\r");
        $this->overlayOpens();
        $this->key("\r"); // pick "keep"
        $this->key("\x1b[B"); // Delete → Cancel
        $this->key("\r"); // choose Cancel
        $this->pumpUntil(fn (): bool => !EditorState::pickerOpen($this->editor));

        $this->assertStringContainsString('keep', $this->project->git('branch'), 'the branch survived the cancel');
    }

    public function testBranchesCreateIgnoresAnEmptyName(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $before = $this->project->git('branch');

        $commands->branches();
        $this->overlayOpens();
        $this->key("\r"); // "+ Create new branch…"
        $this->key("\r"); // submit an empty name
        $this->pumpUntil(fn (): bool => !EditorState::pickerOpen($this->editor));

        $this->assertSame($before, $this->project->git('branch'), 'no branch was created');
    }

    public function testBranchesChecksOutARemoteBranchAsTracking(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        // A bare remote carrying a branch this repo does not have locally.
        $remote = new TempProject([]);
        $remote->git('init', '--bare', '-q');
        $this->project->git('remote', 'add', 'origin', $remote->dir);
        $this->project->git('push', '-q', 'origin', 'HEAD:refs/heads/shipped');
        $this->project->git('fetch', '-q', 'origin');

        $commands->branches();
        $this->overlayOpens();
        // Rows: [+ Create], [− Delete], [+ New worktree], main (the one local), origin/shipped.
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\r");
        $this->pumpUntil();

        $this->assertStringContainsString('shipped', $this->statusRow($this->statusBar));
        $this->assertSame('shipped', \trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD')));
        $remote->__destruct();
    }

    public function testBranchesDeleteReportsWhenThereIsNothingToDelete(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->branches();
        $this->overlayOpens();
        $this->key("\x1b[B"); // "− Delete branch…"
        $this->key("\r");
        $this->pumpUntil();

        $this->assertStringContainsString('No other local branches to delete', $this->statusRow($this->statusBar));
    }

    // --- Branches and worktrees -----------------------------------------------

    public function testABranchAnotherWorktreeHasOutSaysWhereAndOpensIt(): void
    {
        // git refuses to check it out here ("already used by worktree"), so the
        // row goes where the branch is instead of failing.
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->worktreeFolder = new TempProject([]);
        $linked = $this->worktreeFolder->dir.'/feat';
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $linked);

        $commands->branches();
        $this->overlayOpens();
        $items = $this->overlayItems();
        $feat = \array_first(\array_filter($items, static fn (array $i): bool => '  feat' === $i['label']));
        $this->assertNotNull($feat);
        $this->assertSame('in worktree feat', $feat['hint']);
        $this->type('feat');
        $this->key("\r");

        $this->assertSame([\realpath($linked)], \array_map(\realpath(...), $this->opened));
        $this->assertNotSame('feat', \trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD')), 'nothing was checked out here');
    }

    public function testTheBranchOutHereIsNotSentToAWorktree(): void
    {
        // Every branch out anywhere has a worktree — this one's too. Only the
        // OTHERS' are somewhere to go; this one's is where the user already is.
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->worktreeFolder = new TempProject([]);
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->worktreeFolder->dir.'/feat');
        $current = \trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD'));

        $commands->branches();
        $this->overlayOpens();
        $row = \array_first(\array_filter($this->overlayItems(), static fn (array $i): bool => '✓ '.$current === $i['label']));
        $this->assertNotNull($row);
        $this->assertSame('current', $row['hint']);
        $this->assertSame($current, $row['value'], 'a checkout, not a switch');
    }

    public function testABranchWhoseWorktreeFolderIsGoneIsGitsToExplain(): void
    {
        // Nothing to open there; git's refusal to check it out says where it is.
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->worktreeFolder = new TempProject([]);
        $this->project->git('worktree', 'add', '-q', '-b', 'gone', $this->worktreeFolder->dir.'/gone');
        new Filesystem()->remove($this->worktreeFolder->dir.'/gone');

        $commands->branches();
        $this->overlayOpens();
        $this->type('gone');
        $this->key("\r");
        $this->pumpUntil();

        $this->assertSame([], $this->opened);
        $this->assertStringContainsString('already used by worktree', $this->statusRow($this->statusBar));
    }

    public function testTheBranchPickerLeadsToANewWorktree(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->branches();
        $this->overlayOpens();
        $this->type('New worktree');
        $this->key("\r");
        $this->pumpUntil(fn (): bool => EditorState::promptOpen($this->editor), what: 'the worktree name prompt');

        $this->assertTrue(EditorState::promptOpen($this->editor));
    }

    public function testABranchAnotherWorktreeHasOutIsNotOfferedForDeletion(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->worktreeFolder = new TempProject([]);
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->worktreeFolder->dir.'/feat');

        $commands->deleteBranch();
        $this->pumpUntil();

        $this->assertStringContainsString('No other local branches to delete', $this->statusRow($this->statusBar), 'git would refuse it: "used by worktree"');
    }

    public function testAnOldGitStillSwitchesBranches(): void
    {
        // Before 2.36 `worktree list -z` fails; the picker carries on without it.
        $this->project = new TempProject(['a.txt' => "one\n"]);
        $this->project->initGit(branch: 'main');
        $this->project->commitAll('init');
        $script = <<<'SH'
            #!/bin/sh
            for a in "$@"; do
                if [ "$a" = worktree ]; then echo "error: unknown switch \`z'" >&2; exit 129; fi
            done
            exec REAL "$@"
            SH;
        $fake = new TempProject(['git' => \str_replace('REAL', (string) $this->whichGit(), $script)."\n"]);
        \chmod($fake->path('git'), permissions: 0o755);
        $commands = $this->build(new GitInfo($this->project->dir, gitPath: $fake->path('git')));

        $commands->branches();
        $this->overlayOpens();

        $this->assertContains('✓ main', \array_column($this->overlayItems(), 'label'));
    }

    /** @return list<array{label: string, hint: string, value: string}> */
    private function overlayItems(): array
    {
        /** @var array{items: list<array{label: string, hint: string, value: string}>}|null $overlay */
        $overlay = Probe::get(Probe::object($this->editor, 'overlayHost'), 'overlay');

        return $overlay['items'] ?? [];
    }

    public function testBranchesWithNoRepositoryReportsUnavailable(): void
    {
        $commands = $this->commandsOutsideRepo(['a.txt' => "one\n"]);

        $commands->branches();
        $this->pumpUntil();

        $this->assertStringContainsString('Git not available', $this->statusRow($this->statusBar));
    }

    // --- Fixture --------------------------------------------------------------

    /** A GitCommands over a fresh repo with one initial commit. @param array<string, string> $files */
    private function commands(array $files): GitCommands
    {
        $this->project = new TempProject($files);
        $this->project->initGit();
        $this->project->commitAll('init');

        return $this->build();
    }

    /** A GitCommands over a directory that is NOT a git repo. @param array<string, string> $files */
    private function commandsOutsideRepo(array $files): GitCommands
    {
        $this->project = new TempProject($files);

        return $this->build();
    }

    /** @var list<string> what the tree would report as selected */
    private array $selected = [];

    /** @var list<array{list<string>, string}> what the compare command published */
    private array $published = [];

    private function build(?GitInfo $git = null): GitCommands
    {
        $this->lane = new ProcessLane();
        $git ??= new GitInfo($this->project->dir, lane: $this->lane);
        $this->editor = new CodeEditorWidget();
        $this->statusBar = new StatusBarWidget();
        $this->tui = TestTui::create(100, 24);
        $translator = new Translator('en');
        $refresher = new GitRefresher($git, static function (): void {});

        $clipboard = new Clipboard($this->tui);

        return new GitCommands(
            $git,
            GitDoubles::modals($this->editor),
            GitDoubles::flash(new Notifier($this->statusBar, $this->tui)),
            $this->tui->requestRender(...),
            GitDoubles::translate($translator),
            $refresher,
            $clipboard->copy(...),
            $this->events = new EventDispatcher(),
            fn (): array => $this->selected,
            function (array $lines, string $title): void {
                $this->published[] = [$lines, $title];
            },
            new WorktreeCommands(
                $git,
                GitDoubles::modals($this->editor),
                GitDoubles::flash(new Notifier($this->statusBar, $this->tui)),
                $this->tui->requestRender(...),
                GitDoubles::translate($translator),
                function (string $path, string $repeat): void {
                    $this->opened[] = $path;
                },
                fn (): string => $this->worktreeFolder->dir ?? '',
                $this->project->dir,
            ),
        );
    }

    /** Every git this rig's commands start: idle again is the barrier for a verb that should have run none. */
    private ProcessLane $lane;

    /**
     * Pump until no git is running. A verb's git starts in the same call that
     * asks for it, so idle right after a key means it ran and answered — or
     * never ran: either way, whatever it would flash has flashed.
     */
    private function gitSettles(): void
    {
        $this->pumpUntil(fn (): bool => 0 === $this->lane->inFlight(), what: 'git to settle');
    }

    /** @var list<string> the folders the worktree commands asked core to open */
    private array $opened = [];

    private ?TempProject $worktreeFolder = null;

    /** Run git synchronously via Symfony Process; returns stdout. */
    private function porcelain(): string
    {
        return $this->project->git('status', '--porcelain');
    }

    private function write(string $rel, string $contents): void
    {
        \file_put_contents($this->project->dir.'/'.$rel, $contents);
    }

    private function readFile(string $rel): string
    {
        // Guard the read: git replaces the file by atomic rename during a stash,
        // and a poll landing in that instant would otherwise warn on the
        // momentarily-absent path.
        $path = $this->project->dir.'/'.$rel;

        return \is_file($path) ? (string) \file_get_contents($path) : '';
    }

    private function openInEditor(string $rel, string $contents): void
    {
        $this->editor->showDocument($this->project->dir.'/'.$rel, new Document($contents));
    }

    // --- Comparing two files --------------------------------------------------

    public function testComparingTwoSelectedFilesPublishesTheirDiff(): void
    {
        $commands = $this->commands(['a.php' => "<?php\n\$x = 1;\n", 'b.php' => "<?php\n\$x = 2;\n"]);
        $this->selected = [$this->project->path('a.php'), $this->project->path('b.php')];

        $commands->compareFiles();
        $this->pumpUntil(fn (): bool => [] !== $this->published, what: 'the comparison to publish');

        $this->assertCount(1, $this->published);
        [$lines, $title] = $this->published[0];
        $this->assertSame('a.php ↔ b.php', $title, 'the panel says which two, because the rows do not');
        $joined = \implode("\n", $lines);
        $this->assertStringContainsString('-$x = 1;', $joined);
        $this->assertStringContainsString('+$x = 2;', $joined);
        $this->assertStringStartsWith('@@', $lines[0], 'the rows start at the first hunk: five lines of git header would push the first real change out of a short panel');
    }

    public function testComparingTwoIdenticalFilesSaysSoInsteadOfOpeningAnEmptyPanel(): void
    {
        $commands = $this->commands(['a.php' => "<?php\n", 'b.php' => "<?php\n"]);
        $this->selected = [$this->project->path('a.php'), $this->project->path('b.php')];

        $commands->compareFiles();
        $this->pumpUntil();

        $this->assertSame([], $this->published);
        $this->assertStringContainsString('identical', $this->flashText($this->statusBar));
    }

    /** Two binary files that differ print no hunk either: they are said to differ, not to be identical. */
    public function testComparingTwoDifferentBinaryFilesSaysTheyDiffer(): void
    {
        $commands = $this->commands(['a.bin' => "\x00\x01one", 'b.bin' => "\x00\x01two"]);
        $this->selected = [$this->project->path('a.bin'), $this->project->path('b.bin')];

        $commands->compareFiles();
        $this->pumpUntil();

        $this->assertSame([], $this->published);
        $this->assertStringContainsString('Binary files differ', $this->flashText($this->statusBar));
    }

    public function testComparingNeedsExactlyTwoFiles(): void
    {
        // One is what the tree always has — its cursor row — so "compare" with
        // one selected is the ordinary mistake, and the flash has to say what
        // to do rather than nothing happening.
        $commands = $this->commands(['a.php' => "<?php\n", 'b.php' => "<?php\n"]);
        $this->selected = [$this->project->path('a.php')];

        $commands->compareFiles();
        $this->pumpUntil();

        $this->assertSame([], $this->published);
        $this->assertStringContainsString('two files', $this->flashText($this->statusBar));
    }

    public function testComparingOutsideARepositorySaysWhatEveryGitVerbSays(): void
    {
        // `git diff --no-index` needs no repository, and this command still
        // does — the plugin's runner answers every call with a failed run when
        // there is no working tree, as the one place that knows the
        // availability rule. Pinned rather than worked around: a comparison is
        // available exactly where the rest of the git plugin is.
        $commands = $this->commandsOutsideRepo(['a.php' => "<?php\n\$x = 1;\n", 'b.php' => "<?php\n\$x = 2;\n"]);
        $this->selected = [$this->project->path('a.php'), $this->project->path('b.php')];

        $commands->compareFiles();
        $this->pumpUntil();

        $this->assertSame([], $this->published);
        $this->assertStringContainsString('Git not available', $this->flashText($this->statusBar));
    }
}
