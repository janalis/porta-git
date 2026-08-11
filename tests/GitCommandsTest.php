<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\GitPlugin\GitCommands;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\I18n\Translator;
use Porta\Support\Clipboard;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
    use StatusReads;

    private EventDispatcher $events;
    private TempProject $project;
    private CodeEditorWidget $editor;
    private StatusBarWidget $statusBar;
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
        $this->pump();

        $this->assertStringContainsString('Committed: a fresh commit', $this->statusRow($this->statusBar));
        $this->assertStringContainsString('a fresh commit', $this->project->git('log', '-1', '--pretty=%s'));
    }

    public function testCommitWithAnEmptyMessageDoesNothing(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $before = trim($this->project->git('rev-parse', 'HEAD'));

        $commands->commit();
        $this->key("\r"); // submit with an empty message
        $this->drain(); // a non-event: nothing will flash, so there is nothing to await

        $this->assertSame($before, trim($this->project->git('rev-parse', 'HEAD')), 'no commit was made');
        // HEAD alone proves nothing here — git refuses an empty message on its
        // own, so this passed even with the early return deleted. What the
        // guard actually buys is that git is never RUN: without it the user
        // gets "Commit failed (nothing staged?)" for having typed nothing.
        $this->assertSame('', trim($this->statusRow($this->statusBar)), 'and git was never run, so nothing was flashed');
    }

    public function testCommitWithNoRepositoryReportsUnavailable(): void
    {
        $commands = $this->commandsOutsideRepo(['a.txt' => "one\n"]);

        $commands->commit();
        $this->pump();

        $this->assertStringContainsString('Git not available', $this->statusRow($this->statusBar));
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
    }

    private static function commitEntry(string $sha): LogEntry
    {
        return new LogEntry($sha, substr($sha, offset: 0, length: 7), 'Tester', 0, 'subject');
    }

    /** @param callable(GitCommands): void $action */
    #[\PHPUnit\Framework\Attributes\DataProvider('outsideARepo')]
    public function testActionsRefuseOutsideARepository(callable $action): void
    {
        $commands = $this->commandsOutsideRepo(['a.txt' => "one\n"]);

        $action($commands);
        $this->pump();

        $this->assertStringContainsString('Git not available', $this->statusRow($this->statusBar));
    }

    public function testStashesFlashesWhenThereAreNone(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->stashes();
        $this->pump();

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
        $this->pump();

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
        $this->pump();

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
        $this->pump();

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
        $this->pump(fn (): bool => !EditorState::pickerOpen($this->editor));

        $this->assertStringContainsString('wip', $this->project->git('stash', 'list'), 'the stash is untouched');
    }

    // --- Commit-history context actions ---------------------------------------

    public function testCopyCommitHashCopiesToTheClipboardAndFlashes(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $sha = trim($this->project->git('rev-parse', 'HEAD'));

        $commands->copyCommitHash(self::commitEntry($sha));

        $this->assertStringContainsString('Copied '.substr($sha, offset: 0, length: 7), $this->statusRow($this->statusBar));
        $this->assertStringContainsString("\x1b]52;c;".base64_encode($sha), $this->tui->getTerminal()->getOutput(), 'the full hash reached the OSC 52 clipboard');
    }

    public function testBranchFromCommitCreatesABranchAtThatCommit(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $first = trim($this->project->git('rev-parse', 'HEAD'));
        $this->write('a.txt', "one\ntwo\n");
        $this->project->git('commit', '-am', 'second');

        $commands->branchFromCommit(self::commitEntry($first));
        $this->type('from-first');
        $this->key("\r");
        $this->pump();

        $this->assertStringContainsString('Created and switched to from-first', $this->statusRow($this->statusBar));
        $this->assertSame($first, trim($this->project->git('rev-parse', 'from-first')), 'the new branch points at the chosen commit');
    }

    public function testBranchFromCommitWithAnEmptyNameDoesNothing(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $before = trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD'));

        $commands->branchFromCommit(self::commitEntry(trim($this->project->git('rev-parse', 'HEAD'))));
        $this->key("\r"); // submit an empty name — the prompt returns early

        $this->assertSame($before, trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD')), 'no branch created or switched');
    }

    public function testCherryPickAppliesACommitOntoTheCurrentBranch(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->project->git('checkout', '-b', 'side');
        $this->write('b.txt', "new\n");
        $this->project->git('add', 'b.txt');
        $this->project->git('commit', '-m', 'add b');
        $pick = trim($this->project->git('rev-parse', 'HEAD'));
        $this->project->git('checkout', '-'); // back to the starting branch — b.txt is gone

        $commands->cherryPick(self::commitEntry($pick));
        // Await the completion flash, not the on-disk effect: git writes b.txt
        // *before* its process exits, so under load a file-based pump can win
        // the race against the exit callback that sets the flash.
        $this->pump(fn (): bool => str_contains($this->statusRow($this->statusBar), 'Cherry-picked'));

        $this->assertStringContainsString('Cherry-picked', $this->statusRow($this->statusBar));
        $this->assertFileExists($this->project->dir.'/b.txt');
    }

    public function testRevertCommitUndoesItWithANewCommit(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\nadded\n");
        $this->project->git('commit', '-am', 'add line');
        $bad = trim($this->project->git('rev-parse', 'HEAD'));

        $commands->revertCommit(self::commitEntry($bad));
        // Await the completion flash — the working-tree rewrite precedes the
        // exit callback that sets it (see the cherry-pick test above).
        $this->pump(fn (): bool => str_contains($this->statusRow($this->statusBar), 'Reverted'));

        $this->assertStringContainsString('Reverted', $this->statusRow($this->statusBar));
        $this->assertStringNotContainsString('added', $this->readFile('a.txt'), 'the reverting commit removed the line');
    }

    public function testResetToCommitSoftMovesHeadAndKeepsTheWorkingTree(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $base = trim($this->project->git('rev-parse', 'HEAD'));
        $this->write('a.txt', "one\nmore\n");
        $this->project->git('commit', '-am', 'more');

        $commands->resetToCommit(self::commitEntry($base));
        $this->overlayOpens();
        $this->key("\r"); // "Soft" leads the confirm list
        $this->pump();

        $this->assertStringContainsString('Reset to '.substr($base, offset: 0, length: 7).' (soft)', $this->statusRow($this->statusBar));
        $this->assertSame($base, trim($this->project->git('rev-parse', 'HEAD')), 'HEAD moved to the chosen commit');
        $this->assertStringContainsString('more', $this->readFile('a.txt'), 'a soft reset leaves the working tree untouched');
    }

    public function testResetToCommitCancelLeavesHeadAlone(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->write('a.txt', "one\nmore\n");
        $this->project->git('commit', '-am', 'more');
        $tip = trim($this->project->git('rev-parse', 'HEAD'));

        $commands->resetToCommit(self::commitEntry($tip));
        $this->overlayOpens();
        $this->key("\x1b[B"); // → Mixed
        $this->key("\x1b[B"); // → Cancel
        $this->key("\r"); // pick Cancel: onPick returns without touching git

        $this->assertSame($tip, trim($this->project->git('rev-parse', 'HEAD')), 'cancelling changes nothing');
    }

    public function testFileHistoryWithNoOpenFileReportsUnavailable(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->fileHistory();
        $this->pump();

        $this->assertStringContainsString('Git not available', $this->statusRow($this->statusBar));
    }

    public function testFileHistoryWithAnOpenFileIsAcceptedWhenNoGitViewIsAttached(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->openInEditor('a.txt', "one\n");

        // WorkspaceViews has no git controller here, so this is a no-op — but
        // it must reach that branch without flashing "unavailable".
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
        $this->pump();

        $this->assertStringContainsString('Created and switched to feature', $this->statusRow($this->statusBar));
        $this->assertSame('feature', trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD')));
    }

    public function testNewBranchOpensTheCreatePromptDirectly(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->newBranch(); // the status-bar branch menu's "New Branch…"
        $this->type('feature');
        $this->key("\r");
        $this->pump();

        $this->assertStringContainsString('Created and switched to feature', $this->statusRow($this->statusBar));
        $this->assertSame('feature', trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD')));
    }

    public function testDeleteBranchOpensThePickerDirectly(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->project->git('branch', 'stale');

        $commands->deleteBranch(); // the status-bar branch menu's "Delete Branch…"
        $this->overlayOpens(); // the deletable list
        $this->key("\r"); // pick "stale" (the only deletable)
        $this->key("\r"); // confirm "Delete"
        $this->pump();

        $this->assertStringContainsString('Deleted branch stale', $this->statusRow($this->statusBar));
    }

    public function testBranchActionsReportUnavailableOutsideARepo(): void
    {
        $this->commandsOutsideRepo(['a.txt' => "one\n"])->newBranch();
        $this->assertStringContainsString('Git not available', $this->statusRow($this->statusBar));

        $this->commandsOutsideRepo(['a.txt' => "one\n"])->deleteBranch();
        $this->assertStringContainsString('Git not available', $this->statusRow($this->statusBar));
    }

    public function testBranchesSwitchesToAnExistingBranch(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);
        $this->project->git('branch', 'other');

        $commands->branches();
        $this->overlayOpens();
        // Rows: [+ Create], [− Delete], main (current), other. Move to "other".
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\r");
        $this->pump();

        $this->assertStringContainsString('Switched to other', $this->statusRow($this->statusBar));
        $this->assertSame('other', trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD')));
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
        $this->pump();

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
        $this->pump(fn (): bool => !EditorState::pickerOpen($this->editor));

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
        $this->pump(fn (): bool => !EditorState::pickerOpen($this->editor));

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
        // Rows: [+ Create], [− Delete], main (the one local), origin/shipped.
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\x1b[B");
        $this->key("\r");
        $this->pump();

        $this->assertStringContainsString('shipped', $this->statusRow($this->statusBar));
        $this->assertSame('shipped', trim($this->project->git('rev-parse', '--abbrev-ref', 'HEAD')));
        $remote->__destruct();
    }

    public function testBranchesDeleteReportsWhenThereIsNothingToDelete(): void
    {
        $commands = $this->commands(['a.txt' => "one\n"]);

        $commands->branches();
        $this->overlayOpens();
        $this->key("\x1b[B"); // "− Delete branch…"
        $this->key("\r");
        $this->pump();

        $this->assertStringContainsString('No other local branches to delete', $this->statusRow($this->statusBar));
    }

    public function testBranchesWithNoRepositoryReportsUnavailable(): void
    {
        $commands = $this->commandsOutsideRepo(['a.txt' => "one\n"]);

        $commands->branches();
        $this->pump();

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

    private function build(): GitCommands
    {
        $git = new GitInfo($this->project->dir);
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
        );
    }

    /** Run git synchronously via Symfony Process; returns stdout. */
    private function porcelain(): string
    {
        return $this->project->git('status', '--porcelain');
    }

    private function write(string $rel, string $contents): void
    {
        file_put_contents($this->project->dir.'/'.$rel, $contents);
    }

    private function readFile(string $rel): string
    {
        // Guard the read: git replaces the file by atomic rename during a stash,
        // and a poll landing in that instant would otherwise warn on the
        // momentarily-absent path.
        $path = $this->project->dir.'/'.$rel;

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function openInEditor(string $rel, string $contents): void
    {
        $this->editor->showDocument($this->project->dir.'/'.$rel, new Document($contents));
    }

    /** Type printable characters into the open prompt. */
    private function type(string $text): void
    {
        foreach (mb_str_split($text) as $ch) {
            $this->key($ch);
        }
    }

    /** Feed one key event to the editor (routed to the open prompt/overlay). */
    private function key(string $data): void
    {
        $this->editor->handleInput($data);
    }

    /**
     * Pump the event loop until $until holds, then drain a few follow-up
     * turns. With no predicate it just waits for a flash to land — the signal
     * the simple actions end on; the overlay flows wait for the list to open.
     */
    private function pump(?callable $until = null, float $timeout = 10.0, string $what = 'a flash to land'): void
    {
        $until ??= fn (): bool => '' !== trim($this->statusRow($this->statusBar));
        $elapsed = 0.0;
        while (!$until()) {
            if ($elapsed >= $timeout) {
                // Throws rather than falling through, like PumpsLoop::await:
                // a silent expiry runs the assertion below it against pre-op
                // state, which reads as a wrong result instead of a slow one.
                throw new \RuntimeException(\sprintf('timed out after %.1fs waiting for %s', $timeout, $what));
            }
            $this->tick();
            $elapsed += 0.01;
        }
        // Drain any callbacks the resolved op queued behind it (refreshers).
        for ($i = 0; $i < 3; ++$i) {
            $this->tick();
        }
    }

    /**
     * Pump for a fixed span with nothing to await — the deliberate cousin of
     * {@see pump()}, for the tests that assert a NON-event ("no commit was
     * made", "it did not flash unavailable"). Those cannot use pump(): its
     * predicate waits for a flash that by definition never lands, so they were
     * relying on the timeout falling through silently. Prefer pump() whenever
     * a real condition exists.
     */
    private function drain(float $seconds = 0.2): void
    {
        $elapsed = 0.0;
        while ($elapsed < $seconds) {
            $this->tick();
            $elapsed += 0.01;
        }
    }

    /** Waits for the editor's list overlay (branch/stash pickers). */
    private function overlayOpens(): void
    {
        $this->pump(fn (): bool => EditorState::pickerOpen($this->editor));
    }

    private function tick(): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay(0.01, $suspension->resume(...));
        $suspension->suspend();
    }
}
