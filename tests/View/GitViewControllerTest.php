<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\Widget\DiffViewWidget;
use Porta\Event\GitRemoteCompleted;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Command\RefreshMode;
use Porta\GitPlugin\Event\GitStageAllRequested;
use Porta\GitPlugin\StagedState;
use Porta\GitPlugin\Tests\Support\GitViewRig;
use Porta\GitPlugin\View\GitViewController;
use Porta\GitPlugin\View\GitViewMode;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\Probe;
use Porta\Workbench\Menu\Menu;
use Revolt\EventLoop;
use Revolt\EventLoop\InvalidCallbackError;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Drives GitViewController against a real git repository with a real Tui on a
 * virtual terminal — no full Ide boot, so every controller branch is cheap to
 * reach. Skipped when git is absent.
 */
final class GitViewControllerTest extends TestCase
{
    use GitViewRig;

    public function testTheControllerSeedsItsOwnRegionOnConstruction(): void
    {
        // WorkspaceLayout used to stack the three sidebar widgets at
        // construction and PaneLayout used to hand the diff pane in as a bare
        // widget — the two places core layout named this view's contents. The
        // controller does both now, and switchMode() only refills on a CHANGE,
        // so a missed seed is a git view that opens empty with nothing failing.
        $this->controller();

        $kinds = static fn (ContainerWidget $c): array => \array_map(static fn (AbstractWidget $w): string => $w::class, $c->all());

        $this->assertSame(
            [GitTabsWidget::class, ChangesListWidget::class, CommitBoxWidget::class],
            $kinds($this->region->sidebar()),
            'seeded for the initial Changes mode',
        );
        $this->assertSame([DiffViewWidget::class], $kinds($this->region->content()), 'and the diff pane, which is fixed for both modes');
    }

    public function testOpenIsRefusedOutsideARepository(): void
    {
        $controller = $this->controller(realRepo: Presence::Without);
        $controller->open();

        $this->assertFalse($controller->isOpen());
        $this->assertStringContainsString('Not a git repository', $this->statusRow($this->statusBar));
    }

    public function testArmPollReplacesTheTimerSoAnIntervalEditAppliesToAnOpenView(): void
    {
        // The interval is baked into an EventLoop::repeat when the view opens,
        // so a Settings edit made with the view UP reached the holder and
        // nothing else. armPoll() is what the settings hook calls; it must
        // CANCEL the old timer, not merely add a second one.
        $controller = $this->controller();
        $controller->open();
        $first = Probe::get($controller, 'poll');
        $this->assertIsString($first, 'open() armed the poll');

        $controller->armPoll();

        $second = Probe::get($controller, 'poll');
        $this->assertIsString($second);
        $this->assertNotSame($first, $second, 'a fresh timer, at whatever interval is configured now');
        $this->assertTrue(self::cancelled($first), 'and the old one is gone, not left running beside it');
        $this->assertFalse(self::cancelled($second));
    }

    public function testArmPollIsANoOpWhileTheViewIsClosed(): void
    {
        // Every settings write on this plugin reaches the hook, so arming a
        // poll for a closed view would leak a repeating timer per edit — and
        // open() arms it with the current value anyway.
        $controller = $this->controller();

        $controller->armPoll();

        $this->assertNull(Probe::get($controller, 'poll'));
    }

    /** Whether $id has been cancelled: the driver forgets a cancelled callback entirely. */
    private static function cancelled(string $id): bool
    {
        try {
            EventLoop::isEnabled($id);

            return false;
        } catch (InvalidCallbackError) {
            return true;
        }
    }

    public function testToggleOpensTheWindowAndEmptiesIt(): void
    {
        $controller = $this->controller();
        $ids = fn (): array => \array_map(static fn (AbstractWidget $w): ?string => $w->getId(), $this->main->all());

        $this->menuBar->setMenus([new Menu('File', [])]);
        $this->menuBar->open(0);
        $controller->toggle();
        $this->assertTrue($controller->isOpen());
        $this->assertSame(['git-header', 'git-area'], $ids(), 'the region is in the window, its toolbar row above it');
        $this->assertFalse($this->menuBar->isOpen(), 'an open dropdown closes as the window opens');
        $controller->open(); // idempotent
        $this->assertTrue($controller->isOpen());

        $controller->toggle();
        $this->assertFalse($controller->isOpen());
        $this->assertSame([], $ids(), 'the window holds nothing once closed');
        $this->assertSame($this->editor, $this->tui->getFocus(), 'close restores focus to the editor area');
        $controller->close(); // idempotent
        $this->assertSame([], $ids());

        $controller->toggle(); // second round trip stays stable
        $controller->toggle();
        $this->assertSame([], $ids());
    }

    public function testFocusTargetFollowsTheActiveTab(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));
        $this->assertSame($this->changes, $controller->focusTarget());

        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => 2 === \count($this->gitLeft->all()));
        $this->assertSame($this->history, $controller->focusTarget());
    }

    public function testFocusNextAndPreviousWalkThePanesBothWays(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->tui->setFocus($this->changes);
        $controller->focusNext();
        $this->assertSame($this->diffView, $this->tui->getFocus(), 'changes → diff');
        $controller->focusNext();
        $this->assertSame($this->commitBox, $this->tui->getFocus(), 'diff → commit box');
        $controller->focusNext();
        $this->assertSame($this->changes, $this->tui->getFocus(), 'wraps back to the changes list');

        $controller->cycleFocus(-1);
        $this->assertSame($this->commitBox, $this->tui->getFocus(), 'reverse: changes → commit box');
        $controller->cycleFocus(-1);
        $this->assertSame($this->diffView, $this->tui->getFocus(), 'commit box → diff');

        // Focus outside the git panes enters at the changes list either way.
        $this->tui->setFocus($this->menuBar);
        $controller->cycleFocus(-1);
        $this->assertSame($this->changes, $this->tui->getFocus());
    }

    public function testRefreshPopulatesEntriesAndLoadsTheWorktreeDiff(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one changed\ntwo\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $this->assertSame('a.txt', $this->changes->selectedEntry()?->path);
        $this->await(fn (): bool => \str_contains($this->diffText(), '+one changed'));
        $this->assertStringContainsString('-one', $this->diffText());
    }

    public function testStagedOnlyFileShowsTheCachedDiffAndUnstages(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one staged\ntwo\n");
        $this->project->git('add', 'a.txt');
        $controller->open();
        $this->await(fn (): bool => StagedState::Full === $this->changes->selectedEntry()?->stagedState());
        $this->await(fn (): bool => \str_contains($this->diffText(), '+one staged'), what: 'the cached diff loads');

        // Space on a fully staged file unstages it.
        $this->changes->setFocused(true);
        $this->changes->handleInput(' ');
        $this->await(fn (): bool => StagedState::None === $this->changes->selectedEntry()?->stagedState());
        $this->assertSame([' M a.txt'], $this->porcelain());
    }

    public function testUntrackedFileDiffAndStageRoundTrip(): void
    {
        $controller = $this->controller();
        $this->project->write('new.txt', "fresh\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());
        $this->await(fn (): bool => \str_contains($this->diffText(), '+fresh'));

        $this->changes->setFocused(true);
        $this->changes->handleInput(' '); // stage the untracked file
        $this->await(fn (): bool => StagedState::Full === $this->changes->selectedEntry()?->stagedState());
        $this->assertSame(['A  new.txt'], $this->porcelain());
    }

    public function testDiscardTrackedRestoresTheWorktree(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "mangled\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $this->changes->setFocused(true);
        $this->changes->handleInput('d');
        $this->changes->handleInput('y');
        // Await the flash (fired after the restore's exit), not the worktree
        // state — git cleans the file before its process exits.
        $this->await(fn (): bool => \str_contains($this->statusRow($this->statusBar), 'Discarded changes to a.txt'));

        $this->assertSame("one\ntwo\n", \file_get_contents($this->project->path('a.txt')));
        $this->assertSame([], $this->porcelain());
    }

    /** Discard on a STAGED change goes back to HEAD: `restore` alone copied the index — the change — over itself, and said it was done. */
    public function testDiscardingAStagedChangeGoesBackToHead(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "staged edit\n");
        $this->project->write('new.txt', "brand new\n");
        $this->project->git('add', 'a.txt', 'new.txt');
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        foreach (['a.txt', 'new.txt'] as $path) {
            $entry = \array_find(Probe::get($controller, 'entries'), static fn (ChangeEntry $e): bool => $path === $e->path);
            $this->assertInstanceOf(ChangeEntry::class, $entry);
            Probe::get($controller, 'staging')->discard($entry, static function (): void {});
            $this->await(fn (): bool => \str_contains($this->statusRow($this->statusBar, 160), 'Discarded changes to '.$path));
        }

        $this->assertSame("one\ntwo\n", \file_get_contents($this->project->path('a.txt')), 'the staged edit is gone, index and disk');
        $this->assertFileDoesNotExist($this->project->path('new.txt'), 'the staged new file is gone, as an untracked one would be');
        $this->assertSame([], $this->porcelain());
    }

    /** A staged rename discards back to its old name; unstaged, both sides leave the index, not only the new name. */
    public function testAStagedRenameDiscardsAndUnstagesWhole(): void
    {
        $controller = $this->controller();
        $this->project->git('mv', 'a.txt', 'b.txt');
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());
        $rename = $this->changes->selectedEntry();
        $this->assertSame('a.txt', $rename?->renamedFrom, 'the premise: one rename entry');

        Probe::invoke($controller, 'toggleStage', $rename);
        $this->await(fn (): bool => ['?? b.txt', ' D a.txt'] === $this->porcelain() || [' D a.txt', '?? b.txt'] === $this->porcelain(), what: 'both sides unstaged');

        $this->project->git('add', '-A');
        $controller->refresh();
        $this->await(fn (): bool => 'a.txt' === $this->changes->selectedEntry()?->renamedFrom);
        Probe::get($controller, 'staging')->discard($this->changes->selectedEntry(), static function (): void {});
        $this->await(fn (): bool => [] === $this->porcelain(), what: 'back to HEAD');
        $this->assertFileExists($this->project->path('a.txt'));
        $this->assertFileDoesNotExist($this->project->path('b.txt'));
    }

    public function testCommitSuccessAndFailurePaths(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "committed change\ntwo\n");
        $this->project->git('add', 'a.txt');
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $this->commitBox->setFocused(true);
        foreach (\str_split('Change a') as $ch) {
            $this->commitBox->handleInput($ch);
        }
        $this->commitBox->handleInput("\x1b[13;5u"); // ctrl+enter
        $this->await(fn (): bool => 1 === $this->committed);
        $this->assertStringContainsString('Committed:', $this->statusRow($this->statusBar));
        $this->assertSame('', $this->commitBox->message(), 'the box clears after a commit');

        // Second commit with a clean index: git refuses, the failure path flashes.
        $this->await(fn (): bool => null === $this->changes->selectedEntry());
        foreach (\str_split('Nothing') as $ch) {
            $this->commitBox->handleInput($ch);
        }
        // commitEnabled() blocks submit() now, so drive the pipeline directly
        // through a forced submit with a staged predicate bypassed via reflection.
        Probe::invoke($controller, 'commit', 'Nothing');
        $this->await(fn (): bool => \str_contains($this->statusRow($this->statusBar), 'Commit failed'));
        $this->assertSame(1, $this->committed, 'a failed commit fires no onCommitted');
    }

    public function testAmendPrefillsTheLastMessageAndRewritesTheTip(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));

        // alt+m arms amend and prefills the last commit's message.
        $this->commitBox->setFocused(true);
        $this->commitBox->handleInput("\x1bm");
        $this->await(fn (): bool => 'init' === $this->commitBox->message());
        $this->assertTrue($this->commitBox->commitEnabled(), 'amend needs nothing staged');

        // Reword and commit: the tip is rewritten, not extended.
        foreach (\str_split(' reworded') as $ch) {
            $this->commitBox->handleInput($ch);
        }
        $this->commitBox->handleInput("\x1b[13;5u");
        // Terminal-state await: a real git failure (lock contention, fs
        // hiccup) fails fast with git's own message instead of a bare
        // 10s timeout on a success-only signal.
        $this->await(fn (): bool => 1 === $this->committed || \str_contains($this->statusRow($this->statusBar), 'Commit failed'), what: 'the amend to land or fail');
        $this->assertSame(1, $this->committed, 'the amend committed: '.$this->statusRow($this->statusBar));
        $subjects = \explode("\n", $this->project->git('log', '--format=%s'));
        $this->assertSame(['init reworded'], $subjects);
        $this->assertFalse($this->commitBox->isAmend(), 'the box clears (and disarms) after committing');

        // Toggling amend off wipes the prefill.
        $this->commitBox->handleInput("\x1bm");
        $this->await(fn (): bool => 'init reworded' === $this->commitBox->message());
        $this->commitBox->handleInput("\x1bm");
        $this->assertSame('', $this->commitBox->message());
    }

    public function testStalePrefillIsDroppedWhenAmendDisarmsFirst(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));

        // Fire the prefill, then disarm before git answers: the late message
        // must not repopulate a box the user already toggled back off.
        $this->commitBox->setFocused(true);
        $this->commitBox->handleInput("\x1bm");
        $this->commitBox->handleInput("\x1bm");
        $this->pump(0.3);
        $this->assertSame('', $this->commitBox->message());
    }

    public function testCommitStaysDisabledWithOnlyUnstagedChanges(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "dirty\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $this->commitBox->setFocused(true);
        $this->commitBox->handleInput('x'); // summary non-empty
        $this->assertFalse($this->commitBox->commitEnabled(), 'nothing staged: the predicate says no');
    }

    /** A conflict left: git refuses to commit, so the button does not offer it — a `UU` entry used to read as partly staged. */
    public function testCommitStaysDisabledWhileAConflictIsLeft(): void
    {
        $controller = $this->controller();
        $this->project->write('b.txt', "staged\n");
        $this->project->git('add', 'b.txt');
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());
        Probe::set($controller, 'entries', [...Probe::get($controller, 'entries'), new ChangeEntry('c.txt', 'U', 'U')]);

        $this->commitBox->setFocused(true);
        $this->commitBox->handleInput('x');
        $this->assertFalse($this->commitBox->commitEnabled());
    }

    public function testStageFailureFlashes(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));

        $ghost = new ChangeEntry('does-not-exist.txt', ' ', 'M');
        Probe::invoke($controller, 'toggleStage', $ghost);
        $this->await(fn (): bool => \str_contains($this->statusRow($this->statusBar), 'git refused the staging change'));
        $this->addToAssertionCount(1); // the throwing awaits above are the assertions
    }

    /** The header box, end to end: `a` stages every change, untracked included, and `a` again unstages them all. */
    public function testTheHeaderBoxStagesAndUnstagesEverything(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "changed\n");
        $this->project->write('b.txt', "new\n");
        $controller->open();
        $this->await(fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($this->changes->render(new RenderContext(44, 6))[0]), '[ ] 2 changed files'));

        $this->changes->handleInput('a');
        $this->await(fn (): bool => ['M  a.txt', 'A  b.txt'] === $this->porcelain(), what: 'a stages everything');
        $this->await(fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($this->changes->render(new RenderContext(44, 6))[0]), '[✓]'), what: 'and the box says so');

        $this->changes->handleInput('a');
        $this->await(fn (): bool => [' M a.txt', '?? b.txt'] === $this->porcelain(), what: 'a on a full box unstages everything');
        $this->addToAssertionCount(1); // the throwing awaits are the assertions
    }

    public function testStageAllFailureFlashes(): void
    {
        $controller = $this->controller(Presence::Without); // no repository: git refuses
        $controller->open();

        $controller->onStageAllRequested(new GitStageAllRequested(true));

        $this->await(fn (): bool => \str_contains($this->statusRow($this->statusBar), 'git refused the staging change'));
        $this->addToAssertionCount(1);
    }

    public function testDiffArrivingAfterCloseIsDropped(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "late\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $entry = $this->changes->selectedEntry();
        Probe::invoke($controller, 'loadDiff', $entry);
        $controller->close(); // the in-flight diff callback must no-op now
        $this->pump(0.3);
        $this->assertFalse($controller->isOpen());
    }

    public function testLineStagingAndUnstagingThroughTheDiffPane(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one CHANGED\ntwo CHANGED\n");
        $controller->open();
        $this->await(fn (): bool => \str_contains($this->diffText(), '+one CHANGED'));

        // Stage only the 'one' pair via the diff pane: v-range over rows 1-2.
        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\x1b[B"); // -one
        $this->diffView->handleInput('v');
        $this->diffView->handleInput("\x1b[B"); // -two... rows: header, -one, -two, +one, +two? git pairs deletions first
        $this->diffView->handleInput("\x1b[B"); // include +one
        $this->diffView->handleInput(' ');
        $this->await(fn (): bool => [] !== \array_filter($this->porcelain(), static fn (string $l): bool => \str_starts_with($l, 'MM')));

        // Now the file is partially staged; selecting it again shows the
        // worktree remainder. Unstage via the cached side: make the worktree
        // clean for the staged part by... simpler: fully stage, then unstage a line.
        $this->project->git('add', 'a.txt');
        $controller->refresh();
        $this->await(fn (): bool => StagedState::Full === $this->changes->selectedEntry()?->stagedState());
        $this->await(fn (): bool => \str_contains($this->diffText(), '+two CHANGED'), what: 'cached diff loads');

        // refresh() and the selection change race same-path diff reloads; a
        // late one would reset the cursor after we position it. Wait until the
        // rendered diff and the cursor hold still before driving the cursor.
        $snapshot = '';
        $stable = 0;
        $this->await(
            function () use (&$snapshot, &$stable): bool {
                $diff = $this->diffText();
                $now = $diff.Probe::get($this->diffView, 'cursor');
                $stable = $now === $snapshot ? $stable + 1 : 0;
                $snapshot = $now;

                // Stability AND the content, because stability alone is a
                // property an EMPTY pane has perfectly. This loop used to
                // return on eight identical turns of "No changes" — the state a
                // superseded `git status` left behind before GitViewController
                // learned to drop stale answers — and everything after it then
                // waited twenty seconds for an unstage that never went out.
                return $stable >= 8 && \str_contains($diff, '+two CHANGED');
            },
            timeout: 5.0,
            what: 'the cached diff to settle',
        );

        // Page up pins the cursor on the hunk header before unstaging the WHOLE hunk.
        $this->diffView->handleInput("\x1b[5~");
        $this->diffView->handleInput(' ');
        // 20s, not the default: this barrier spans TWO serial git subprocesses
        // (the unstage apply, then the status refresh), and the default timed
        // out three times in one day under external load waves (30-75).
        $this->await(fn (): bool => StagedState::None === $this->changes->selectedEntry()?->stagedState(), timeout: 20.0, what: 'the hunk unstage to reach git status');
        $this->addToAssertionCount(1); // the throwing awaits above are the assertions
    }

    public function testAStaleStatusAnswerCannotRepublishThePreStagingSnapshot(): void
    {
        // Two `git status` calls overlap in ordinary use — a staging completion
        // refreshes while the quiet poll's status is still out — and their
        // answers come back in ARRIVAL order, which under load is not issue
        // order. Applying the older one republishes the pre-staging entry list,
        // whose entry still reads "worktree dirty"; the reload it then drives
        // asks for the WORKTREE diff of a now-fully-staged file, gets nothing,
        // and leaves shownDocument null — after which every space in the diff
        // pane silently no-ops. That is what timed out
        // testLineStagingAndUnstagingThroughTheDiffPane at 1 run in 30 on a
        // loaded machine, twenty seconds spent waiting for an unstage that was
        // never requested.
        $this->skipWithoutGit();
        $useShim = true;
        $shim = null;
        // By REFERENCE, and a closure rather than an arrow fn: $shim is assigned
        // below and $useShim is flipped three times, so a by-value capture would
        // pin both at their values here and quietly run the real binary
        // throughout — which is a test that passes against the bug.
        $controller = $this->controller(gitPath: static function () use (&$useShim, &$shim): ?string {
            return $useShim ? $shim : null;
        });
        $release = $this->gitHoldingItsAnswer($shim);
        $useShim = false; // open() and everything up to the forced race runs on the real binary

        $this->project->write('a.txt', "one CHANGED\ntwo\n");
        $controller->open();
        $this->await(fn (): bool => \str_contains($this->diffText(), '+one CHANGED'), what: 'the worktree diff to load');
        $this->awaitLanes([$this->lane()]); // a clean start: nothing else is in flight
        $this->freezeStatusPoll($controller);

        // The soon-to-be-stale status, blocked in the shim mid-call. It carries
        // the worktree-dirty snapshot that is about to stop being true.
        $useShim = true;
        $controller->refresh();
        $useShim = false;
        $this->awaitCapture(); // its answer is now genuinely older than the tree

        // The newer truth, on the real binary, overtaking it.
        $this->project->git('add', 'a.txt');
        $controller->refresh();
        $this->await(fn (): bool => StagedState::Full === $this->changes->selectedEntry()?->stagedState(), what: 'the newer status to apply');
        $this->await(fn (): bool => \str_contains($this->diffText(), '+one CHANGED'), what: 'the cached diff to load');

        // Let the older answer come back last. awaitLanes is the barrier that
        // it was not merely spawned but REAPED — ProcessLane drops a process
        // from its map immediately before invoking the exit callback, so an
        // empty lane observed from a later loop turn means that callback has
        // already run, and been dropped.
        $release();
        $this->awaitLanes([$this->lane()]);

        $this->assertSame(StagedState::Full, $this->changes->selectedEntry()?->stagedState(), 'the stale snapshot did not come back');
        $this->assertNotNull(Probe::get($controller, 'shownDocument'), 'the diff pane still has a document to stage from');
        $this->assertStringContainsString('+one CHANGED', $this->diffText(), 'and it is still the cached diff');
    }

    public function testAStaleDiffAnswerCannotBlankThePaneOverANewerOne(): void
    {
        // The same ordering hazard one level down, and reachable on its own:
        // stageSelection's completion reloads the diff for the entry it
        // CAPTURED before staging, while the refresh it fires alongside reloads
        // the same path from the fresh entry. Two `git diff` answers, two
        // sides, arrival order deciding — and the stale side is the worktree of
        // a file that is now fully staged, which is empty.
        $this->skipWithoutGit();
        $useShim = true;
        $shim = null;
        // By REFERENCE, and a closure rather than an arrow fn: $shim is assigned
        // below and $useShim is flipped three times, so a by-value capture would
        // pin both at their values here and quietly run the real binary
        // throughout — which is a test that passes against the bug.
        $controller = $this->controller(gitPath: static function () use (&$useShim, &$shim): ?string {
            return $useShim ? $shim : null;
        });
        $release = $this->gitHoldingItsCall($shim);
        $useShim = false;

        $this->project->write('a.txt', "one CHANGED\ntwo\n");
        $controller->open();
        $this->await(fn (): bool => \str_contains($this->diffText(), '+one CHANGED'), what: 'the worktree diff to load');
        $this->awaitLanes([$this->lane()]);
        $this->freezeStatusPoll($controller);

        // A worktree-side load for the entry as it reads NOW, held mid-call.
        $entry = $this->changes->selectedEntry();
        $this->assertInstanceOf(ChangeEntry::class, $entry);
        $useShim = true;
        Probe::invoke($controller, 'loadDiff', $entry);
        $useShim = false;

        // Stage the file, then let a newer load win the pane with the cached side.
        $this->project->git('add', 'a.txt');
        $controller->refresh();
        $this->await(fn (): bool => StagedState::Full === $this->changes->selectedEntry()?->stagedState(), what: 'the status to see the staged file');
        $this->await(fn (): bool => \str_contains($this->diffText(), '+one CHANGED'), what: 'the cached diff to load');

        $release();
        $this->awaitLanes([$this->lane()]);

        $this->assertNotNull(Probe::get($controller, 'shownDocument'), 'the superseded worktree load did not blank the pane');
        $this->assertStringContainsString('+one CHANGED', $this->diffText(), 'the newer cached diff still holds the pane');
    }

    public function testStageSelectionGuardsUntrackedAndEmptySelections(): void
    {
        $controller = $this->controller();
        $this->project->write('new.txt', "fresh\n");
        $controller->open();
        $this->await(fn (): bool => \str_contains($this->diffText(), '+fresh'));

        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\x1b[B");
        $this->diffView->handleInput(' '); // untracked: flash, no patch
        $this->await(fn (): bool => \str_contains($this->statusRow($this->statusBar), 'stage the whole file'));
        $this->assertSame(['?? new.txt'], $this->porcelain(), 'nothing was staged');

        // Guard: no shown document at all.
        Probe::set($controller, 'shownDocument', null);
        Probe::invoke($controller, 'stageSelection', [0 => [0 => true]]);
        $this->assertSame(['?? new.txt'], $this->porcelain());
    }

    public function testApplyFailureFlashes(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "edited\ntwo\n");
        $controller->open();
        $this->await(fn (): bool => \str_contains($this->diffText(), '+edited'));

        // A context-only selection builds no patch and is a silent no-op.
        Probe::invoke($controller, 'stageSelection', [0 => [2 => true]]);
        $this->assertStringNotContainsString('git apply', $this->statusRow($this->statusBar));

        // Corrupt the worktree state between diff load and apply: replace the
        // file so the crafted patch no longer matches the index.
        $this->project->git('add', 'a.txt'); // index = edited
        $this->diffView->setFocused(true);
        $this->diffView->handleInput(' '); // whole first hunk, built against the OLD worktree diff
        $this->await(fn (): bool => \str_contains($this->statusRow($this->statusBar), 'git apply refused'));
    }

    public function testDiffPrefsApplyOnOpenAndPersistOnToggle(): void
    {
        // Seeded BEFORE the controller: the prefs are read on open, out of the
        // same plugin settings the toggle writes back into.
        $this->diffPrefs = ['diffSplit' => true, 'diffHideWhitespace' => false];
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));
        $this->assertTrue($this->diffView->isSplit(), 'the persisted split preference applies on open');

        $this->diffView->setFocused(true);
        $this->diffView->handleInput('s'); // back to unified: persists
        $this->assertSame(['diffSplit' => false, 'diffHideWhitespace' => false], $this->diffPrefs, 'the unified toggle persisted');
    }

    public function testCtrlCCopiesTheSelectedDiffLines(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one CHANGED\ntwo CHANGED\n");
        $controller->open();
        $this->await(fn (): bool => \str_contains($this->diffText(), '+one CHANGED'));

        // Range over the two deletions, then copy: plain code, no markers.
        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\x1b[B"); // -one
        $this->diffView->handleInput('v');
        $this->diffView->handleInput("\x1b[B"); // -two
        $this->diffView->handleInput("\x03");

        $this->assertStringContainsString("\x1b]52;c;".\base64_encode("one\ntwo\n"), $this->tui->getTerminal()->getOutput(), 'the diff selection reached the OSC 52 clipboard');
        $this->assertStringContainsString('Copied 2 lines', $this->statusRow($this->statusBar));
    }

    public function testWhitespaceToggleHidesWhitespaceHunksAndBlocksStaging(): void
    {
        $controller = $this->controller();
        // An indent-only change on 'one' plus a real change on 'two'.
        $this->project->write('a.txt', "  one\ntwo CHANGED\n");
        $controller->open();
        $this->await(fn (): bool => \str_contains($this->diffText(), '+  one'));

        $this->diffView->setFocused(true);
        $this->diffView->handleInput('w');
        $this->await(fn (): bool => !\str_contains($this->diffText(), '+  one'), what: 'the indent-only pair disappears');
        $this->assertStringContainsString('+two CHANGED', $this->diffText(), 'real changes survive');
        $this->assertStringContainsString('Whitespace changes hidden', $this->statusRow($this->statusBar));
        $this->assertSame(['diffSplit' => false, 'diffHideWhitespace' => true], $this->diffPrefs, 'the preference persisted');

        // Staging is blocked while whitespace is hidden: a -w diff cannot
        // produce an appliable patch.
        $this->diffView->handleInput("\x1b[B");
        $this->diffView->handleInput(' ');
        $this->await(fn (): bool => \str_contains($this->statusRow($this->statusBar), 'Show whitespace changes to stage lines'));
        $this->assertSame([' M a.txt'], $this->porcelain(), 'nothing was staged');

        $this->diffView->handleInput('w'); // back on
        $this->await(fn (): bool => \str_contains($this->diffText(), '+  one'));
        $this->assertStringContainsString('Whitespace changes shown', $this->statusRow($this->statusBar));
    }

    public function testWhitespaceOnlyChangeShowsThePlaceholder(): void
    {
        $this->diffPrefs = ['diffSplit' => false, 'diffHideWhitespace' => true]; // persisted from a previous session
        $controller = $this->controller();
        $this->project->write('a.txt', "  one\ntwo\n"); // reindent only
        $controller->open();

        $this->await(fn (): bool => \str_contains($this->diffText(), 'No changes (whitespace hidden)'));
        $this->addToAssertionCount(1); // the throwing awaits above are the assertions
    }

    public function testWhitespaceToggleAppliesToHistoryDiffs(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "  one\ntwo CHANGED\n");
        $this->project->git('add', '-A');
        $this->project->git('commit', '-q', '-m', 'reindent');
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History: the reindent commit auto-loads a.txt
        $this->await(fn (): bool => \str_contains($this->diffText(), '+  one'));

        $this->diffView->setFocused(true);
        $this->diffView->handleInput('w');
        $this->await(fn (): bool => !\str_contains($this->diffText(), '+  one'), what: 'the history patch reloads with -w');
        $this->assertStringContainsString('+two CHANGED', $this->diffText());
    }

    public function testContextCycleReloadsTheDiffWithMoreContextAndWrapsAround(): void
    {
        $controller = $this->controller();
        $body = \implode("\n", \array_map(static fn (int $i): string => 'line '.$i, \range(1, end: 30)))."\n";
        $this->project->write('big.txt', $body);
        $this->project->git('add', '-A');
        $this->project->git('commit', '-q', '-m', 'big');
        $this->project->write('big.txt', \str_replace('line 15', replace: 'line 15 EDITED', subject: $body));
        $controller->open();
        $this->await(fn (): bool => \str_contains($this->diffText(), '+line 15 EDITED'));
        $this->assertStringNotContainsString('line 5 ', $this->diffText(), 'default context stops three lines out');

        $this->diffView->setFocused(true);
        $this->diffView->handleInput('c');
        $this->await(fn (): bool => \str_contains($this->diffText(), 'line 5 '), what: '-U10 pulls in farther context');
        $this->assertStringContainsString('Diff context: ±10 lines', $this->statusRow($this->statusBar));
        $this->assertStringNotContainsString('line 1 ', $this->diffText());

        $this->diffView->handleInput('c');
        $this->await(fn (): bool => \str_contains($this->diffText(), 'line 1 '), what: 'whole-file context starts at line 1');
        $this->assertStringContainsString('Diff context: whole file', $this->statusRow($this->statusBar));

        $this->diffView->handleInput('c'); // wraps back to the default
        $this->await(fn (): bool => !\str_contains($this->diffText(), 'line 5 '));
        $this->assertStringContainsString('Diff context: default', $this->statusRow($this->statusBar));
    }

    public function testContextCycleGuardsUntrackedAndMissingDiffs(): void
    {
        $controller = $this->controller();
        $this->project->write('new.txt', "fresh\n");
        $controller->open();
        $this->await(fn (): bool => \str_contains($this->diffText(), '+fresh'));

        // Untracked diffs are already whole-file: flash, no reload.
        $this->diffView->setFocused(true);
        $this->diffView->handleInput('c');
        $this->assertStringContainsString('Untracked files always show the whole file', $this->statusRow($this->statusBar));

        // No shown entry at all: a silent no-op.
        Probe::set($controller, 'shownEntry', null);
        Probe::invoke($controller, 'cycleContext');
        $this->assertStringContainsString('Untracked files', $this->statusRow($this->statusBar), 'no new flash replaced the old one');
    }

    /** The view open on History over two commits — a second on top of the fixture's — the newest selected. */
    private function onHistoryOfTwoCommits(): GitViewController
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one\ntwo\nthree\n");
        $this->project->git('add', '-A');
        $this->project->git('commit', '-q', '-m', 'second');
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // switch to History
        $this->await(fn (): bool => null !== $this->history->selectedCommit());

        return $controller;
    }

    public function testHistoryModeBrowsesCommitsAndShowsReadOnlyDiffs(): void
    {
        $this->onHistoryOfTwoCommits();
        $this->assertSame(
            ['git-tabs', 'git-history'],
            \array_map(static fn (AbstractWidget $w): ?string => $w->getId(), $this->gitLeft->all()),
            'the left pane swapped to the history list (tabs stay on top)',
        );
        $this->assertSame('second', $this->history->selectedCommit()?->subject);
        $this->assertTrue(Probe::get($this->diffView, 'readOnly'));
        $this->assertSame(
            ['git-commit-header', 'git-columns'],
            \array_map(static fn (AbstractWidget $w): ?string => $w->getId(), $this->region->content()->all()),
            'the content is the commit header over GitHub Desktop\'s middle column and the diff',
        );
        $split = $this->region->split('columns');
        $this->assertSame([$this->details, $this->diffView], [$split->lead(), $split->rest()]);

        // The files of the newest commit load beside it, and the FIRST file's
        // patch shows immediately — no keyboard step needed to see a diff.
        $this->await(fn (): bool => \str_contains($this->detailsText(), 'a.txt'));
        $this->assertStringContainsString('second', $this->detailsText(), 'under the commit\'s own header');
        $this->await(fn (): bool => \str_contains($this->diffText(), '+three'), what: "the first file's patch auto-loads on commit selection");

        // Enter moves into the files, and browsing them still refires loads.
        $this->history->setFocused(true);
        $this->history->handleInput("\r");
        $this->assertSame($this->details, $this->tui->getFocus(), 'Enter hands the keys to the files');
        $this->details->setFocused(true);
        $this->details->handleInput("\x1b[B");
        $this->details->handleInput("\x1b[A");
        $this->await(fn (): bool => \str_contains($this->diffText(), '+three'), what: 'the commit file patch renders');
        $this->details->handleInput("\r");
        $this->assertSame($this->history, $this->tui->getFocus(), 'and Enter hands them back');

        // Staged keys are inert in read-only mode.
        $this->diffView->setFocused(true);
        $this->diffView->handleInput(' ');
        $this->assertSame([], $this->porcelain(), 'nothing staged from history');

        // Back to Changes: left pane restored, the diff alone and editable again.
        $this->history->setFocused(true);
        $this->history->handleInput('1');
        $this->await(fn (): bool => 3 === \count($this->gitLeft->all()));
        $this->assertFalse(Probe::get($this->diffView, 'readOnly'));
        $this->assertSame([$this->diffView], $this->region->content()->all());
    }

    public function testRightClickSelectsAHistoryCommitForItsContextMenu(): void
    {
        $this->onHistoryOfTwoCommits();
        $this->history->render(new RenderContext(44, 20)); // set the row geometry

        $commit = $this->history->selectCommitAt(1); // the newest commit's subject row
        $this->assertSame('second', $commit?->subject, 'a right-click selects the commit under it');
        $this->assertSame(1, $this->history->selectedCommitRow(), 'its menu opens under its who-and-when row');

        // Selecting a different commit moves the selection (and fires the callback).
        $older = $this->history->selectCommitAt(4);
        $this->assertNotSame('second', $older?->subject, 'the next entry is an older commit');
        $this->assertSame(4, $this->history->selectedCommitRow());

        $this->assertNull($this->history->selectCommitAt(2), 'the hairline between two is no commit');
        $this->assertNull($this->history->selectCommitAt(6), 'an empty commit slot is no commit');
        $this->assertNull($this->history->selectCommitAt(99), 'past the list is no commit');
    }

    public function testEmptyCommitShowsAPlaceholderInsteadOfAutoLoading(): void
    {
        $controller = $this->controller();
        $this->project->git('commit', '-q', '--allow-empty', '-m', 'hollow');
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History: the empty commit is newest
        $this->await(fn (): bool => 'hollow' === $this->history->selectedCommit()?->subject);
        $this->await(fn (): bool => \str_contains($this->diffText(), 'No files in this commit'));
        $this->addToAssertionCount(1); // the throwing awaits above are the assertions
    }

    public function testReopeningAfterHistoryModeLandsOnChanges(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => 'git-history' === ($this->gitLeft->all()[1] ?? null)?->getId());

        $controller->close();
        $controller->open(); // must reset to the Changes tab
        $this->await(fn (): bool => 3 === \count($this->gitLeft->all()));
        $this->assertFalse(Probe::get($this->diffView, 'readOnly'));

        // History-mode focus cycle: commits → files → diff → commits.
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => 2 === \count($this->gitLeft->all()));
        $this->history->setFocused(true);
        $this->history->handleInput("\t");
        $this->assertSame($this->details, $this->tui->getFocus());
        $this->details->setFocused(true);
        $this->details->handleInput("\t");
        $this->assertSame($this->diffView, $this->tui->getFocus());
        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\t");
        $this->assertSame($this->history, $this->tui->getFocus());
    }

    public function testStaleHistoryCallbacksAreDropped(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        Probe::invoke($controller, 'switchMode', GitViewMode::History); // same mode: no-op
        $this->await(fn (): bool => null !== $this->history->selectedCommit());

        // A log request whose view closes before it resolves is dropped.
        $controller->close();
        $controller->open(); // back on Changes
        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // fires git log...
        $controller->close(); // ...and closes before it lands
        $this->pump(0.3);
        $this->assertFalse($controller->isOpen());
        $controller->open();

        $commit = $this->history->selectedCommit();
        // Fire the loaders, then flip back to Changes before they resolve:
        // both callbacks must drop their results.
        Probe::invoke($controller, 'loadCommitFiles', $commit);
        Probe::invoke($controller, 'loadCommitFileDiff', $commit, 'a.txt');
        $this->history->setFocused(true);
        $this->history->handleInput('1');
        $this->pump(0.3);
        $this->assertFalse(Probe::get($this->diffView, 'readOnly'), 'back on Changes, untouched by stale history data');
    }

    public function testMovingTheHistorySelectionLoadsWhatItLandsOn(): void
    {
        // Both loaders are private and were reached only through Probe. The
        // keyboard test above navigates and re-asserts the SAME patch, so it
        // passes whether or not the reload happened; emptying either listener
        // was invisible. This one makes every hop change what is on screen.
        $controller = $this->controller();
        \file_put_contents($this->project->dir.'/a.txt', data: "one\ntwo\nthree\n");
        $this->project->commitAll('add three');
        $controller->open();
        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History
        $this->await(fn (): bool => 2 === $this->history->commitCount());
        $this->await(fn (): bool => \str_contains($this->diffText(), '+three'), what: 'the newest commit loads on arrival');

        // Down to the init commit: only GitCommitSelected can load its files,
        // and .gitignore sorts first, so its patch is what must appear.
        $this->history->setFocused(true);
        $this->history->handleInput("\x1b[B");
        $this->await(fn (): bool => \str_contains($this->diffText(), '+.porta/'), what: "the older commit's first file loads");
        $this->assertStringContainsString('.gitignore', $this->detailsText());

        // Into that commit's file list and down to a.txt: only
        // GitCommitFileSelected can swap the patch.
        $this->details->setFocused(true);
        $this->details->handleInput("\x1b[B");
        $this->await(fn (): bool => \str_contains($this->diffText(), '+two'), what: 'selecting a file loads that file');
        $this->assertStringNotContainsString('+.porta/', $this->diffText());
    }

    /**
     * A branch with no commit yet — a fresh `git init` — has nothing to amend,
     * so the checkbox opens disabled; the commit that gives it one refreshes
     * eagerly, and the checkbox comes alive with it.
     */
    public function testTheAmendCheckboxWaitsForAFirstCommit(): void
    {
        $controller = $this->controller();
        $this->project->git('update-ref', '-d', 'HEAD'); // the branch unborn, as after `git init`
        $commitBox = fn (): string => \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->commitBox->render(new RenderContext(44, 30))));

        $controller->open();
        $this->await(static fn (): bool => \str_contains($commitBox(), 'No commit to amend'), what: 'the unborn branch disables amend');
        $this->assertStringContainsString('┆   ┆ Amend last commit', $commitBox(), 'dashed');

        $this->project->commitAll('first');
        $controller->refresh();
        $this->await(static fn (): bool => !\str_contains($commitBox(), 'No commit to amend'), what: 'a first commit enables it');
        $this->assertStringContainsString('│   │ Amend last commit', $commitBox());
    }

    public function testFocusCycleWalksTheThreePanes(): void
    {
        $controller = $this->controller();
        $controller->open();

        $this->assertSame($this->changes, $this->tui->getFocus());
        $this->changes->setFocused(true);
        $this->changes->handleInput("\t");
        $this->assertSame($this->diffView, $this->tui->getFocus());
        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\t");
        $this->assertSame($this->commitBox, $this->tui->getFocus());
        $this->commitBox->setFocused(true);
        $this->commitBox->handleInput("\t"); // summary -> description
        $this->commitBox->handleInput("\t"); // description -> the amend checkbox
        $this->commitBox->handleInput("\t"); // amend -> next pane
        $this->assertSame($this->changes, $this->tui->getFocus());
    }

    public function testAQuietTickWithAStatusInFlightSkipsInsteadOfStacking(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "poked\ntwo\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());
        $this->assertFalse((bool) Probe::get($controller, 'statusInFlight'), 'a completed status clears the guard');

        // Simulate a status still running: the quiet tick must not spawn
        // another — a change on disk stays unseen while the guard holds.
        Probe::set($controller, 'statusInFlight', true);
        $this->project->write('extra.txt', "new\n");
        $controller->refresh(RefreshMode::Quiet);
        $this->pump(0.3);
        $entries = \array_map(static fn ($e) => $e->path, Probe::get($controller, 'entries'));
        $this->assertNotContains('extra.txt', $entries, 'the guarded quiet tick spawned nothing');

        // An EAGER refresh (the user asked) bypasses the guard...
        $controller->refresh();
        $this->await(static fn (): bool => \in_array('extra.txt', \array_map(static fn ($e) => $e->path, Probe::get($controller, 'entries')), strict: true), what: 'the eager refresh to land');
        // ...and its completion re-clears the guard for the poll — which is
        // also why a close() cancelling a status mid-flight cannot wedge it:
        // open()'s eager refresh ignores the guard and always answers.
        $this->assertFalse((bool) Probe::get($controller, 'statusInFlight'));
        $controller->close();
    }

    public function testQuietRefreshSkipsRepaintWhenNothingChanged(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "poked\ntwo\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $before = $this->changes->selectedEntry();
        $controller->refresh(RefreshMode::Quiet);
        $this->pump(0.2);
        $this->assertSame($before?->path, $this->changes->selectedEntry()?->path, 'identical status output leaves state alone');

        $controller->close();
        $controller->refresh(); // closed: the status callback drops its result
        $this->pump(0.2);
        $this->assertFalse($controller->isOpen());
    }

    public function testAComparisonFromTheHistoryTabShowsTheTwoFilesAndEndsTheRunWhenClosed(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // the History tab: a comparison must still land on Changes
        $this->await(fn (): bool => $this->history === $controller->focusTarget());
        $controller->close();
        $left = $this->project->write('.porta/left.txt', "one\ntwo\n");
        $right = $this->project->write('.porta/right.txt', "one\nthree\n");
        $ended = 0;

        $controller->openComparison($left, $right, static function () use (&$ended): void {
            ++$ended;
        });
        $this->await(fn (): bool => \str_contains($this->diffText(), 'three'), what: 'the comparison in the diff pane');
        $this->assertSame($this->changes, $controller->focusTarget());

        // A status refresh would put the working tree back in the pane.
        $controller->refresh();
        $this->pump(0.3);
        $this->assertStringContainsString('three', $this->diffText(), 'a comparison is not the working tree');

        $controller->close();
        $this->assertSame(1, $ended, 'closing it ends the difftool run');
        $controller->close();
        $this->assertSame(1, $ended, 'and only once');
    }

    /** @return iterable<string, array{string, string}> the git verb made slow, and what the view does before the comparison */
    public static function answersStillOnTheirWay(): iterable
    {
        yield 'a status, the view closed first' => ['status', 'close'];
        yield 'a status, straight from the open view' => ['status', 'stay'];
        yield 'a file\'s diff, straight from the open view' => ['diff', 'stay'];
    }

    /**
     * The race behind a comparison that "turned into" the working tree: an
     * answer the view asked for BEFORE the comparison — its `git status`, or
     * the diff of the file that status selected — landed after it and was
     * painted over it. A slow verb makes the order certain instead of
     * load-dependent.
     */
    #[DataProvider('answersStillOnTheirWay')]
    public function testAnAnswerStillOnItsWayWhenAComparisonOpensDoesNotOverwriteIt(string $slow, string $view): void
    {
        $git = (string) $this->whichGit();
        $wrapper = \sys_get_temp_dir().'/porta-slow-git-'.\bin2hex(\random_bytes(4));
        $marker = $wrapper.'.done';
        $release = $wrapper.'.go';
        // Held for the repository's own verb only, until the test lets it go
        // once the comparison has painted — not for a fixed second, which on
        // a loaded box let the slow answer land FIRST and the test pass
        // without the stale case ever happening. The comparison is a
        // `diff --no-index`, and is never held.
        $script = <<<SH
            #!/bin/sh
            case " \$* " in *" --no-index "*) exec '{$git}' "\$@" ;; esac
            for a in "\$@"; do [ "\$a" = {$slow} ] && { while [ ! -f '{$release}' ]; do sleep 0.02; done; touch '{$marker}'; break; }; done
            exec '{$git}' "\$@"
            SH;
        \file_put_contents($wrapper, $script."\n");
        \chmod($wrapper, permissions: 0o755);
        try {
            $controller = $this->controller(gitPath: static fn (): string => $wrapper);
            if ('diff' === $slow) {
                $this->project->write('a.txt', "one\nchanged\n");
            }
            $controller->open();
            if ('diff' === $slow) {
                // The status answers; the diff of the file it selects is what is on its way.
                $this->await(fn (): bool => null !== $this->changes->selectedEntry(), what: 'the status, selecting a.txt');
            }
            if ('close' === $view) {
                $controller->close();
            }
            $left = $this->project->write('.porta/left.txt', "one\ntwo\n");
            $right = $this->project->write('.porta/right.txt', "one\nthree\n");
            $controller->openComparison($left, $right, static function (): void {});
            $this->await(fn (): bool => \str_contains($this->diffText(), 'three'), what: 'the comparison in the diff pane');

            \touch($release); // only now: the slow answer is still on its way, as the case needs
            $this->await(static fn (): bool => \is_file($marker), what: 'the slow '.$slow.' to finish');
            $this->pump(0.3); // its answer lands on the loop: proving it changed NOTHING

            $this->assertStringContainsString('three', $this->diffText(), 'the stale answer did not paint the working tree over the comparison');
            $controller->close();
        } finally {
            \touch($release); // never leave a held git waiting, whatever failed
            \array_map(\unlink(...), \array_filter([$wrapper, $marker], \is_file(...)));
            // The release goes once a held git has read it: it polls every 20 ms.
            \usleep(100_000);
            \array_map(\unlink(...), \array_filter([$release], \is_file(...)));
        }
    }

    public function testADirectoryComparisonListsItsFilesAndTouchesNothingInTheRepository(): void
    {
        // `git difftool --dir-diff`: two trees of changed files. The changes
        // list shows THEM, so its stage/discard/commit keys must not reach the
        // index behind it.
        $controller = $this->controller();
        // A real, UNSTAGED working-tree change to a file the comparison lists:
        // any of the row keys reaching the repository would stage or discard it.
        $this->project->write('a.txt', "one\nchanged\n");
        $this->project->write('.porta/l/a.txt', "one\ntwo\n");
        $this->project->write('.porta/l/b.txt', "b\n");
        $this->project->write('.porta/r/a.txt', "one\nTHREE\n");
        $this->project->write('.porta/r/c.txt', "c\n");

        $controller->openComparison($this->project->path('.porta/l'), $this->project->path('.porta/r'), static function (): void {});

        $this->await(fn (): bool => \str_contains($this->diffText(), 'THREE'), what: 'the first changed file compared');
        $list = \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->changes->render(new RenderContext(44, 10))));
        $this->assertMatchesRegularExpression('/a\.txt.*\n.*b\.txt.*\n.*c\.txt/', $list);

        $this->changes->setFocused(true);
        $this->changes->handleInput(' '); // stage a.txt
        $this->changes->handleInput('a'); // stage everything
        $this->changes->handleInput('d');
        $this->changes->handleInput('y'); // discard a.txt, confirmed
        $this->pump(0.3);
        $this->assertSame('M a.txt', $this->project->git('status', '--porcelain'), 'the index and the working tree are untouched');
        $this->assertSame("one\nchanged\n", (string) \file_get_contents($this->project->path('a.txt')));

        $this->changes->handleInput("\x1b[B"); // b.txt: deleted on the right
        $this->await(fn (): bool => \str_contains($this->diffText(), '-b'), what: 'the deleted file against nothing');
    }

    public function testAComparisonClosedBeforeGitAnswersPaintsNothing(): void
    {
        $controller = $this->controller();
        $left = $this->project->write('.porta/left.txt', "one\n");
        $right = $this->project->write('.porta/right.txt', "two\n");

        $controller->openComparison($left, $right, static function (): void {});
        $controller->close();
        $this->pump(0.4);

        $this->assertStringNotContainsString('two', $this->diffText(), 'an answer for a closed comparison is dropped');
    }

    public function testHistoryPagesInOlderCommitsAtTheListBottom(): void
    {
        $controller = $this->controller(historyPageSize: 2);
        foreach (['second', 'third', 'fourth', 'fifth'] as $n) {
            $this->project->git('commit', '-q', '--allow-empty', '-m', $n);
        }
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History tab
        $this->await(fn (): bool => 2 === $this->history->commitCount(), what: 'page 0 loads');
        $this->assertSame('fifth', $this->history->selectedCommit()?->subject);

        // Walking to the bottom pulls page 1; selection stays put.
        $this->history->setFocused(true);
        $this->history->handleInput("\x1b[B");
        $this->await(fn (): bool => 4 === $this->history->commitCount(), what: 'page 1 appended');
        $this->assertSame('fourth', $this->history->selectedCommit()?->subject);

        // And the final partial page ends the paging.
        $this->history->handleInput("\x1b[B");
        $this->history->handleInput("\x1b[B");
        $this->await(fn (): bool => 5 === $this->history->commitCount(), what: 'the last page appended (partial: paging ends)');
        $this->history->handleInput("\x1b[B");
        $this->history->handleInput("\x1b[B");
        $this->pump(0.1);
        $this->assertSame(5, $this->history->commitCount(), 'no further loads past the end');
    }

    public function testRemoteCompletedReloadsHistoryPageZero(): void
    {
        $controller = $this->controller(historyPageSize: 2);
        $this->project->git('commit', '-q', '--allow-empty', '-m', 'second');
        $controller->open();
        $this->await($controller->isOpen(...));

        $controller->onRemoteCompleted(new GitRemoteCompleted('pull', true)); // changes mode: a no-op
        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History tab
        $this->await(fn (): bool => 2 === $this->history->commitCount());

        // A commit lands "remotely" (same repo here); the completed event
        // reloads page 0, so the new commit is in the list.
        $this->project->git('commit', '-q', '--allow-empty', '-m', 'fromremote');
        $controller->onRemoteCompleted(new GitRemoteCompleted('pull', true));
        $this->await(fn (): bool => \str_contains($this->historyText(), 'fromremote'), what: 'the newest commit appears at the top');
        // And the reload is a KeepSelection one, so it does not move you: the
        // commit you had selected is still the selected one, even though a
        // newer one now sits above it.
        $this->assertSame('second', $this->history->selectedCommit()?->subject);
    }

    /**
     * `HistoryReload::KeepSelection` kept nothing: `setCommits()` resets the
     * list unconditionally, so a fetch finishing while you read an older
     * commit threw you back to the top of the history. It also cleared the
     * files pane without touching the diff pane, which went on showing the
     * patch of the commit that was no longer selected — no error, no empty
     * pane, just a patch belonging to somewhere else.
     */
    public function testAPullKeepsTheCommitYouWereReadingAndItsFiles(): void
    {
        $controller = $this->controller(historyPageSize: 20);
        $this->project->write('notes.txt', "alpha\n");
        $this->project->git('add', '-A');
        $this->project->git('commit', '-q', '-m', 'adds-notes');
        $this->project->git('commit', '-q', '--allow-empty', '-m', 'newer');
        $controller->open();
        $this->await($controller->isOpen(...));
        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History tab
        $this->await(fn (): bool => $this->history->commitCount() >= 3);

        // Move off the top commit and open its patch.
        $this->history->setFocused(true);
        $this->history->handleInput("\x1b[B");
        $this->await(fn (): bool => 'adds-notes' === ($this->history->selectedCommit()->subject ?? ''), what: 'the older commit is selected');
        $this->await(fn (): bool => \str_contains($this->diffText(), '+alpha'), what: "adds-notes' patch");

        // A pull brings something newer in and the list reloads underneath.
        $this->project->git('commit', '-q', '--allow-empty', '-m', 'fromremote');
        $controller->onRemoteCompleted(new GitRemoteCompleted('pull', true));
        $this->await(fn (): bool => 4 === $this->history->commitCount(), what: 'the pull reloaded page 0');

        $this->assertSame('adds-notes', $this->history->selectedCommit()?->subject, 'the commit I was reading is still the selected one');
        // The reset emptied the files pane; it has to come back, or the patch
        // still on screen is one nothing in the list points at any more.
        $this->await(
            fn (): bool => null !== $this->details->selectedFile(),
            what: "the kept commit's files reloaded under it",
        );
        $this->assertStringContainsString('+alpha', $this->diffText(), 'and the patch under it is still its own');
    }

    /** A file with no history at all: the pane must say so, not keep the last commit's patch. */
    public function testFilteringHistoryToAFileWithNoCommitsClearsTheDiff(): void
    {
        $controller = $this->controller(historyPageSize: 20);
        $this->project->write('notes.txt', "alpha\n");
        $this->project->git('add', '-A');
        $this->project->git('commit', '-q', '-m', 'adds-notes');
        $controller->open();
        $this->await($controller->isOpen(...));
        $controller->openFileHistory($this->project->path('notes.txt'));
        $this->await(fn (): bool => \str_contains($this->diffText(), '+alpha'), what: "notes.txt's patch");

        $this->project->write('untracked.txt', "nothing committed\n");
        $controller->openFileHistory($this->project->path('untracked.txt'));

        $this->await(fn (): bool => 0 === $this->history->commitCount(), what: 'the filter empties the list');
        $this->await(fn (): bool => \str_contains($this->diffText(), 'No commits'), what: 'the pane says there is nothing, rather than showing notes.txt');
        $this->assertStringNotContainsString('+alpha', $this->diffText());
    }

    public function testHistoryCallbacksLandingAfterCloseAreIgnored(): void
    {
        $controller = $this->controller(historyPageSize: 1);
        $this->project->git('commit', '-q', '--allow-empty', '-m', 'second');
        $controller->open();
        $this->await($controller->isOpen(...));
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => 1 === $this->history->commitCount());

        // Fire a reload and a load-more, then close before either can land:
        // both callbacks must hit the open/mode guard and change nothing.
        $controller->onRemoteCompleted(new GitRemoteCompleted('pull', true));
        $this->history->setFocused(true);
        $this->history->handleInput("\x1b[B"); // bottom row: arms load-more
        $controller->close();
        $this->pump(0.2);
        $this->assertSame(1, $this->history->commitCount(), 'no page landed after close');
    }

    public function testFileHistoryFiltersAndClearsOnTabSwitch(): void
    {
        $controller = $this->controller(historyPageSize: 1);
        $this->project->write('a.txt', "one\ntwo\nthree\n");
        $this->project->git('add', '-A');
        $this->project->git('commit', '-q', '-m', 'touch-a');
        $this->project->write('b.txt', "new\n");
        $this->project->git('add', '-A');
        $this->project->git('commit', '-q', '-m', 'touch-b');

        $controller->openFileHistory($this->project->path('a.txt'));
        $this->await(fn (): bool => 'touch-a' === $this->history->selectedCommit()?->subject);
        $this->assertStringContainsString('History — a.txt', $this->historyText(), 'the header names the filtered file');
        $this->assertStringNotContainsString('touch-b', $this->historyText(), 'commits not touching a.txt are hidden');

        // Paging keeps the filter: the next page is "init", not "touch-b".
        $this->history->setFocused(true);
        $this->history->handleInput("\x1b[B"); // move past the last loaded commit
        $this->await(fn (): bool => \str_contains($this->historyText(), 'init'));
        $this->assertStringNotContainsString('touch-b', $this->historyText());

        // Tabbing to Changes drops the filter; History reopens repo-wide.
        $this->history->handleInput('1');
        $this->await(fn (): bool => 3 === \count($this->gitLeft->all()));
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => \str_contains($this->historyText(), 'touch-b'), what: 'the filter is gone');
        $this->assertStringNotContainsString('History — a.txt', $this->historyText());
    }

    public function testFileHistoryWhileAlreadyOnHistoryReloadsInPlace(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one\ntwo\nthree\n");
        $this->project->git('add', '-A');
        $this->project->git('commit', '-q', '-m', 'touch-a');

        $controller->open();
        $this->await($controller->isOpen(...));
        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History, repo-wide
        $this->await(fn (): bool => null !== $this->history->selectedCommit());

        $controller->openFileHistory($this->project->path('a.txt'));
        $this->await(fn (): bool => \str_contains($this->historyText(), 'History — a.txt'));
        $this->assertSame('touch-a', $this->history->selectedCommit()?->subject);
    }

    public function testOpenInsideARepositoryBelowItsRootSaysWhereGitWorks(): void
    {
        $controller = $this->controller(folder: 'frontend');
        $controller->open();

        $this->assertFalse($controller->isOpen(), 'git paths are the root\'s: nothing here would line up');
        $this->assertStringContainsString('Inside the repository', $this->statusRow($this->statusBar));
    }

    public function testFileHistoryOutsideARepositoryFlashesAndStaysClosed(): void
    {
        $controller = $this->controller(realRepo: Presence::Without);
        $controller->openFileHistory($this->project->path('a.txt'));
        $this->assertFalse($controller->isOpen());
        $this->assertStringContainsString('Not a git repository', $this->statusRow($this->statusBar));
        $this->assertNull(Probe::get($controller, 'historyPath'), 'refused: no filter is kept for a view that never opened');
        $this->assertNull(Probe::get($this->history, 'filterLabel'));
    }

    public function testARemoteKeyFromAPaneReachesTheRemoteCommands(): void
    {
        // The two git panes used to hold a closure each, both calling the same
        // method; now they dispatch and one listener performs. The listener is
        // a single delegating line, so the only assertion worth making is that
        // the operation comes out the far end — which needs a real fetch, here
        // against a repo whose origin is itself.
        $controller = $this->controller();
        $this->project->git('remote', 'add', 'origin', $this->project->dir);
        $controller->open();

        $completed = [];
        $this->dispatcher->addListener(GitRemoteCompleted::class, static function (GitRemoteCompleted $event) use (&$completed): void {
            $completed[] = $event;
        });

        $this->changes->setFocused(true);
        $this->changes->handleInput('f'); // fetch

        $this->await(static function () use (&$completed): bool {
            return [] !== $completed;
        });
        $this->assertSame('fetch', $completed[0]->operation, 'the key press must reach GitRemoteCommands, not merely dispatch');
        $this->assertTrue($completed[0]->ok);
    }
}
