<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\View;

use PHPUnit\Framework\TestCase;
use Porta\Config\LayoutStore;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\GitPlugin\Command\GitCommands;
use Porta\GitPlugin\Command\GitRemoteCommands;
use Porta\GitPlugin\Command\RefreshMode;
use Porta\GitPlugin\Event\GitChangeDiscardRequested;
use Porta\GitPlugin\Event\GitChangeSelected;
use Porta\GitPlugin\Event\GitChangeStageToggleRequested;
use Porta\GitPlugin\Event\GitCommitFileSelected;
use Porta\GitPlugin\Event\GitCommitSelected;
use Porta\GitPlugin\Event\GitDiffContextCycleRequested;
use Porta\GitPlugin\Event\GitDiffCopyRequested;
use Porta\GitPlugin\Event\GitDiffLayoutToggled;
use Porta\GitPlugin\Event\GitDiffStagingRequested;
use Porta\GitPlugin\Event\GitDiffWhitespaceToggleRequested;
use Porta\GitPlugin\Event\GitHistoryEndReached;
use Porta\GitPlugin\Event\GitRemoteRequested;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\Process\GitRemote;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\GitPlugin\View\GitContextMenus;
use Porta\GitPlugin\View\GitMouseRouter;
use Porta\GitPlugin\View\GitPanes;
use Porta\GitPlugin\View\GitViewController;
use Porta\GitPlugin\View\GitViewMode;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Layout\EditorAreaPanes;
use Porta\Layout\LayoutController;
use Porta\Layout\ViewRegion;
use Porta\Layout\ViewRegions;
use Porta\Support\Clipboard;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\AwaitsGit;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Tests\Support\TestTui;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\MenuBarWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewHost;
use Porta\Workbench\View\ViewRegistry;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Drives GitViewController against a real git repository with a real Tui on a
 * virtual terminal — no full Ide boot, so every controller branch is cheap to
 * reach. Skipped when git is absent.
 */
final class GitViewControllerTest extends TestCase
{
    use GitBinary;
    use AwaitsGit;
    use PumpsLoop;
    use StatusReads;

    private TempProject $project;
    /** @var array<string, bool|int|string> the plugin settings DiffPrefs persists into */
    private array $diffPrefs = [];
    private ?GitInfo $git = null;
    /** Held so tearDown can close the view a test left open (the poll timer is armed by open()). */
    private ?GitViewController $controller = null;

    /**
     * A test that opens the view and asserts on layout or focus leaves two
     * things behind: the 2s status-POLL the open armed (a repeating timer that
     * outlives the test, which is most of what this class used to leak), and
     * whatever git subprocesses the refresh started, whose watchers sit on
     * streams their reaped children are about to close.
     *
     * Closing cancels the first, draining waits out the second. Doing it here
     * beats bolting both onto thirty-odd tests that do not otherwise care.
     */
    protected function tearDown(): void
    {
        $this->controller?->close();
        if (null !== $this->git) {
            $this->awaitLanes([Probe::get(Probe::get($this->git, 'runner'), 'lane')]);
        }
    }

    private Tui $tui;
    private ChangesListWidget $changes;
    private DiffViewWidget $diffView;
    private CommitBoxWidget $commitBox;
    private HistoryListWidget $history;
    private GitTabsWidget $tabs;
    private MenuBarWidget $menuBar;
    private ContainerWidget $gitLeft;
    private ViewRegion $region;
    private ContainerWidget $main;
    private StatusBarWidget $statusBar;
    private int $committed = 0;
    private CodeEditorWidget $editor;
    private LayoutController $layoutController;
    private EventDispatcher $dispatcher;

    private function controller(Presence $realRepo = Presence::With, int $historyPageSize = 200): GitViewController
    {
        if (Presence::With === $realRepo && null === $this->whichGit()) {
            $this->markTestSkipped('git not available');
        }
        // .porta/ is ignored so the real LayoutController's pref writes never
        // dirty the porcelain assertions.
        $this->project = new TempProject(['a.txt' => "one\ntwo\n", '.gitignore' => ".porta/\n"]);
        if (Presence::With === $realRepo) {
            $this->project->initGit();
            $this->project->commitAll('init');
        }
        $this->git = new GitInfo($this->project->dir);

        $this->dispatcher = new EventDispatcher();
        $dispatcher = $this->dispatcher;
        $this->changes = new ChangesListWidget(new Translator('en'), events: $dispatcher);
        $this->diffView = new DiffViewWidget(new Translator('en'), events: $dispatcher);
        $this->commitBox = new CommitBoxWidget(new Translator('en'));
        $this->history = new HistoryListWidget(new Translator('en'), events: $dispatcher);
        $this->history->setId('git-history');
        $this->tabs = new GitTabsWidget(new Translator('en'), events: $dispatcher);
        $this->tabs->setId('git-tabs');
        $this->menuBar = new MenuBarWidget();
        $this->menuBar->setId('menu');
        $editWidget = new ContainerWidget();
        $editWidget->setId('edit-stub');
        $this->main = new ContainerWidget();
        $this->main->add($editWidget);
        // The controller mints its own region and seeds the sidebar for the
        // initial Changes mode, so the test asks the registry for the same one
        // instead of hand-stacking the containers.
        $regions = new ViewRegions(Styles::dark());
        $this->statusBar = new StatusBarWidget();
        $this->tui = TestTui::create(100, 24);
        $this->committed = 0;
        $dispatcher->addListener(GitCommitted::class, function (): void {
            ++$this->committed;
        });
        // The restored editor area holds a focusable editor so close() has a
        // real focus target (EditorArea resolves to it with no buffers open).
        $this->editor = new CodeEditorWidget();
        $this->editor->setId('editor');
        $editWidget->add($this->editor);
        $editorArea = new EditorArea(
            new BufferManager(),
            $this->editor,
            new EditorPanes(),
            $this->tui,
        );
        $t = new Translator('en');
        $views = new ViewRegistry();
        $notifier = new Notifier($this->statusBar, $this->tui);
        $renderer = new Renderer(new StyleSheet());
        $this->layoutController = new LayoutController(
            new LayoutStore($this->project->dir),
            $this->tui,
            $renderer,
            new FileTreeWidget($this->project->dir),
            TestBottomDock::create($this->tui, new TerminalPanelWidget(), new ResultsPanelWidget(), new DebugPanelWidget()),
            // The controller declares its own sidebar when it mints the region,
            // so the layout gets it from there rather than being handed one.
            $regions,
            $t,
        );

        // The region arrives already minted, exactly as ViewServices delivers
        // it: core mints it for the registered id before calling the factory.
        $region = $regions->mint('git');
        $host = new ViewHost(
            $this->main,
            $this->menuBar,
            new class($editWidget) implements EditorAreaPanes {
                public function __construct(
                    private readonly ContainerWidget $editWidget,
                ) {}

                public function orderedEditorPanes(): array
                {
                    return [$this->editWidget];
                }
            },
            $editorArea,
            $this->tui,
        );
        $flash = GitDoubles::flash($notifier);
        $trans = GitDoubles::translate($t);
        $repaint = $this->tui->requestRender(...);
        $copy = new Clipboard($this->tui)->copy(...);
        $modals = GitDoubles::modals($this->editor);

        $controller = new GitViewController(
            $this->git,
            new ProjectPaths($this->project->dir),
            new GitPanes($this->changes, $this->diffView, $this->commitBox, $this->history, $this->tabs),
            $region,
            $host,
            new GitMouseRouter(
                new HitTester($renderer),
                $host,
                $this->changes,
                $this->diffView,
                $this->commitBox,
                $this->history,
                $this->tabs,
                $region,
            ),
            new GitContextMenus(
                new HitTester($renderer),
                $host,
                $this->changes,
                $this->history,
                new GitCommands(
                    $this->git,
                    $modals,
                    $flash,
                    $repaint,
                    $trans,
                    new GitRefresher($this->git, static function (): void {}),
                    $copy,
                    $dispatcher,
                ),
                $t,
            ),
            $flash,
            $t,
            $dispatcher,
            GitDoubles::diffPrefs($this->diffPrefs),
            $copy,
            // Wired, because plugin.php wires it: with the nullable dependency
            // left null the remote listener is a no-op, and a test of it can
            // only assert that nothing threw.
            new GitRemoteCommands(new GitRemote($this->project->dir), $flash, $trans, $dispatcher, $modals, $repaint),
            historyPageSize: $historyPageSize,
        );
        $views->register($controller);
        // PaneLayout fills the AREA at runtime; the region's two panes are
        // the controller's own doing and are asserted, not arranged, below.
        $this->region = $regions->mint('git');
        $this->gitLeft = $this->region->sidebar();
        $this->region->area()->add($this->gitLeft)->add($this->region->content());
        // The container compiles these from #[AsEventListener]; a unit test
        // wires the same two by hand.
        $dispatcher->addListener(GitViewModeRequested::class, $controller->onViewModeRequested(...));
        $dispatcher->addListener(GitRemoteRequested::class, $controller->onRemoteRequested(...));
        $dispatcher->addListener(GitChangeSelected::class, $controller->onChangeSelected(...));
        $dispatcher->addListener(GitChangeStageToggleRequested::class, $controller->onChangeStageToggled(...));
        $dispatcher->addListener(GitChangeDiscardRequested::class, $controller->onChangeDiscarded(...));
        $dispatcher->addListener(GitCommitSelected::class, $controller->onCommitSelected(...));
        $dispatcher->addListener(GitCommitFileSelected::class, $controller->onCommitFileSelected(...));
        $dispatcher->addListener(GitHistoryEndReached::class, $controller->onHistoryEndReached(...));
        $dispatcher->addListener(GitDiffStagingRequested::class, $controller->onDiffLinesStaged(...));
        $dispatcher->addListener(GitDiffContextCycleRequested::class, $controller->onDiffContextCycled(...));
        $dispatcher->addListener(GitDiffLayoutToggled::class, $controller->onDiffLayoutToggled(...));
        $dispatcher->addListener(GitDiffWhitespaceToggleRequested::class, $controller->onDiffWhitespaceToggled(...));
        $dispatcher->addListener(GitDiffCopyRequested::class, $controller->onDiffTextCopied(...));

        return $this->controller = $controller;
    }

    /** @return list<string> porcelain rows of the test repo */
    private function porcelain(): array
    {
        exec('git -C '.escapeshellarg($this->project->dir).' status --porcelain 2>/dev/null', $lines);

        return $lines;
    }

    public function testTheControllerSeedsItsOwnRegionOnConstruction(): void
    {
        // WorkspaceLayout used to stack the three sidebar widgets at
        // construction and PaneLayout used to hand the diff pane in as a bare
        // widget — the two places core layout named this view's contents. The
        // controller does both now, and switchMode() only refills on a CHANGE,
        // so a missed seed is a git view that opens empty with nothing failing.
        $this->controller();

        $kinds = static fn (ContainerWidget $c): array => array_map(static fn (AbstractWidget $w): string => $w::class, $c->all());

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

    public function testToggleSwapsTheMainChildrenAndRestoresThem(): void
    {
        $controller = $this->controller();

        $this->menuBar->setMenus([new \Porta\Workbench\Menu\Menu('File', [])]);
        $this->menuBar->open(0);
        $controller->toggle();
        $this->assertTrue($controller->isOpen());
        $this->assertSame(['git-area'], array_map(static fn ($w) => $w->getId(), $this->main->all()));
        $this->assertFalse($this->menuBar->isOpen(), 'an open dropdown closes across the swap');
        $controller->open(); // idempotent
        $this->assertTrue($controller->isOpen());

        $controller->toggle();
        $this->assertFalse($controller->isOpen());
        $this->assertSame(['edit-stub'], array_map(static fn ($w) => $w->getId(), $this->main->all()));
        $this->assertSame($this->editor, $this->tui->getFocus(), 'close restores focus to the editor area');
        $controller->close(); // idempotent
        $this->assertSame(['edit-stub'], array_map(static fn ($w) => $w->getId(), $this->main->all()));

        $controller->toggle(); // second round trip stays stable
        $controller->toggle();
        $this->assertSame(['edit-stub'], array_map(static fn ($w) => $w->getId(), $this->main->all()));
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
        $this->await(fn (): bool => str_contains($this->diffText(), '+one changed'));
        $this->assertStringContainsString('-one', $this->diffText());
    }

    public function testStagedOnlyFileShowsTheCachedDiffAndUnstages(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one staged\ntwo\n");
        $this->project->git('add', 'a.txt');
        $controller->open();
        $this->await(fn (): bool => 'full' === $this->changes->selectedEntry()?->stagedState());
        $this->await(fn (): bool => str_contains($this->diffText(), '+one staged'), what: 'the cached diff loads');

        // Space on a fully staged file unstages it.
        $this->changes->setFocused(true);
        $this->changes->handleInput(' ');
        $this->await(fn (): bool => 'none' === $this->changes->selectedEntry()?->stagedState());
        $this->assertSame([' M a.txt'], $this->porcelain());
    }

    public function testUntrackedFileDiffAndStageRoundTrip(): void
    {
        $controller = $this->controller();
        $this->project->write('new.txt', "fresh\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());
        $this->await(fn (): bool => str_contains($this->diffText(), '+fresh'));

        $this->changes->setFocused(true);
        $this->changes->handleInput(' '); // stage the untracked file
        $this->await(fn (): bool => 'full' === $this->changes->selectedEntry()?->stagedState());
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
        $this->await(fn (): bool => str_contains($this->statusRow($this->statusBar), 'Discarded changes to a.txt'));

        $this->assertSame("one\ntwo\n", file_get_contents($this->project->path('a.txt')));
        $this->assertSame([], $this->porcelain());
    }

    public function testCommitSuccessAndFailurePaths(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "committed change\ntwo\n");
        $this->project->git('add', 'a.txt');
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $this->commitBox->setFocused(true);
        foreach (str_split('Change a') as $ch) {
            $this->commitBox->handleInput($ch);
        }
        $this->commitBox->handleInput("\x1b[13;5u"); // ctrl+enter
        $this->await(fn (): bool => 1 === $this->committed);
        $this->assertStringContainsString('Committed:', $this->statusRow($this->statusBar));
        $this->assertSame('', $this->commitBox->message(), 'the box clears after a commit');

        // Second commit with a clean index: git refuses, the failure path flashes.
        $this->await(fn (): bool => null === $this->changes->selectedEntry());
        foreach (str_split('Nothing') as $ch) {
            $this->commitBox->handleInput($ch);
        }
        // commitEnabled() blocks submit() now, so drive the pipeline directly
        // through a forced submit with a staged predicate bypassed via reflection.
        Probe::invoke($controller, 'commit', 'Nothing');
        $this->await(fn (): bool => str_contains($this->statusRow($this->statusBar), 'Commit failed'));
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
        foreach (str_split(' reworded') as $ch) {
            $this->commitBox->handleInput($ch);
        }
        $this->commitBox->handleInput("\x1b[13;5u");
        // Terminal-state await: a real git failure (lock contention, fs
        // hiccup) fails fast with git's own message instead of a bare
        // 10s timeout on a success-only signal.
        $this->await(fn (): bool => 1 === $this->committed || str_contains($this->statusRow($this->statusBar), 'Commit failed'), what: 'the amend to land or fail');
        $this->assertSame(1, $this->committed, 'the amend committed: '.$this->statusRow($this->statusBar));
        $subjects = explode("\n", $this->project->git('log', '--format=%s'));
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
        $this->awaitTicks(15);
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

    public function testStageFailureFlashes(): void
    {
        $controller = $this->controller();
        $controller->open();
        $this->await($controller->isOpen(...));

        $ghost = new \Porta\GitPlugin\ChangeEntry('does-not-exist.txt', ' ', 'M');
        Probe::invoke($controller, 'toggleStage', $ghost);
        $this->await(fn (): bool => str_contains($this->statusRow($this->statusBar), 'git refused the staging change'));
        $this->addToAssertionCount(1); // the throwing awaits above are the assertions
    }

    public function testDiffArrivingAfterCloseIsDropped(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "late\n");
        $controller->open();
        $this->await(fn (): bool => null !== ($entry = $this->changes->selectedEntry()));

        $entry = $this->changes->selectedEntry();
        Probe::invoke($controller, 'loadDiff', $entry);
        $controller->close(); // the in-flight diff callback must no-op now
        $this->awaitTicks(15);
        $this->assertFalse($controller->isOpen());
    }

    public function testLineStagingAndUnstagingThroughTheDiffPane(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one CHANGED\ntwo CHANGED\n");
        $controller->open();
        $this->await(fn (): bool => str_contains($this->diffText(), '+one CHANGED'));

        // Stage only the 'one' pair via the diff pane: v-range over rows 1-2.
        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\x1b[B"); // -one
        $this->diffView->handleInput('v');
        $this->diffView->handleInput("\x1b[B"); // -two... rows: header, -one, -two, +one, +two? git pairs deletions first
        $this->diffView->handleInput("\x1b[B"); // include +one
        $this->diffView->handleInput(' ');
        $this->await(fn (): bool => [] !== array_filter($this->porcelain(), static fn (string $l): bool => str_starts_with($l, 'MM')));

        // Now the file is partially staged; selecting it again shows the
        // worktree remainder. Unstage via the cached side: make the worktree
        // clean for the staged part by... simpler: fully stage, then unstage a line.
        $this->project->git('add', 'a.txt');
        $controller->refresh();
        $this->await(fn (): bool => 'full' === $this->changes->selectedEntry()?->stagedState());
        $this->await(fn (): bool => str_contains($this->diffText(), '+two CHANGED'), what: 'cached diff loads');

        // refresh() and the selection change race same-path diff reloads; a
        // late one would reset the cursor after we position it. Wait until the
        // rendered diff and the cursor hold still before driving the cursor.
        $snapshot = '';
        $stable = 0;
        $this->await(
            function () use (&$snapshot, &$stable): bool {
                $now = $this->diffText().Probe::get($this->diffView, 'cursor');
                $stable = $now === $snapshot ? $stable + 1 : 0;
                $snapshot = $now;

                return $stable >= 8;
            },
            timeout: 5.0,
            what: 'diff reloads settled',
        );

        // Page up pins the cursor on the hunk header before unstaging the WHOLE hunk.
        $this->diffView->handleInput("\x1b[5~");
        $this->diffView->handleInput(' ');
        $this->await(fn (): bool => 'none' === ($this->changes->selectedEntry()?->stagedState() ?? 'gone'));
        $this->addToAssertionCount(1); // the throwing awaits above are the assertions
    }

    public function testStageSelectionGuardsUntrackedAndEmptySelections(): void
    {
        $controller = $this->controller();
        $this->project->write('new.txt', "fresh\n");
        $controller->open();
        $this->await(fn (): bool => str_contains($this->diffText(), '+fresh'));

        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\x1b[B");
        $this->diffView->handleInput(' '); // untracked: flash, no patch
        $this->await(fn (): bool => str_contains($this->statusRow($this->statusBar), 'stage the whole file'));
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
        $this->await(fn (): bool => str_contains($this->diffText(), '+edited'));

        // A context-only selection builds no patch and is a silent no-op.
        Probe::invoke($controller, 'stageSelection', [0 => [2 => true]]);
        $this->assertStringNotContainsString('git apply', $this->statusRow($this->statusBar));

        // Corrupt the worktree state between diff load and apply: replace the
        // file so the crafted patch no longer matches the index.
        $this->project->git('add', 'a.txt'); // index = edited
        $this->diffView->setFocused(true);
        $this->diffView->handleInput(' '); // whole first hunk, built against the OLD worktree diff
        $this->await(fn (): bool => str_contains($this->statusRow($this->statusBar), 'git apply refused'));
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
        $this->await(fn (): bool => str_contains($this->diffText(), '+one CHANGED'));

        // Range over the two deletions, then copy: plain code, no markers.
        $this->diffView->setFocused(true);
        $this->diffView->handleInput("\x1b[B"); // -one
        $this->diffView->handleInput('v');
        $this->diffView->handleInput("\x1b[B"); // -two
        $this->diffView->handleInput("\x03");

        $this->assertStringContainsString("\x1b]52;c;".base64_encode("one\ntwo\n"), $this->tui->getTerminal()->getOutput(), 'the diff selection reached the OSC 52 clipboard');
        $this->assertStringContainsString('Copied 2 lines', $this->statusRow($this->statusBar));
    }

    public function testWhitespaceToggleHidesWhitespaceHunksAndBlocksStaging(): void
    {
        $controller = $this->controller();
        // An indent-only change on 'one' plus a real change on 'two'.
        $this->project->write('a.txt', "  one\ntwo CHANGED\n");
        $controller->open();
        $this->await(fn (): bool => str_contains($this->diffText(), '+  one'));

        $this->diffView->setFocused(true);
        $this->diffView->handleInput('w');
        $this->await(fn (): bool => !str_contains($this->diffText(), '+  one'), what: 'the indent-only pair disappears');
        $this->assertStringContainsString('+two CHANGED', $this->diffText(), 'real changes survive');
        $this->assertStringContainsString('Whitespace changes hidden', $this->statusRow($this->statusBar));
        $this->assertSame(['diffSplit' => false, 'diffHideWhitespace' => true], $this->diffPrefs, 'the preference persisted');

        // Staging is blocked while whitespace is hidden: a -w diff cannot
        // produce an appliable patch.
        $this->diffView->handleInput("\x1b[B");
        $this->diffView->handleInput(' ');
        $this->await(fn (): bool => str_contains($this->statusRow($this->statusBar), 'Show whitespace changes to stage lines'));
        $this->assertSame([' M a.txt'], $this->porcelain(), 'nothing was staged');

        $this->diffView->handleInput('w'); // back on
        $this->await(fn (): bool => str_contains($this->diffText(), '+  one'));
        $this->assertStringContainsString('Whitespace changes shown', $this->statusRow($this->statusBar));
    }

    public function testWhitespaceOnlyChangeShowsThePlaceholder(): void
    {
        $this->diffPrefs = ['diffSplit' => false, 'diffHideWhitespace' => true]; // persisted from a previous session
        $controller = $this->controller();
        $this->project->write('a.txt', "  one\ntwo\n"); // reindent only
        $controller->open();

        $this->await(fn (): bool => str_contains($this->diffText(), 'No changes (whitespace hidden)'));
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
        $this->await(fn (): bool => str_contains($this->diffText(), '+  one'));

        $this->diffView->setFocused(true);
        $this->diffView->handleInput('w');
        $this->await(fn (): bool => !str_contains($this->diffText(), '+  one'), what: 'the history patch reloads with -w');
        $this->assertStringContainsString('+two CHANGED', $this->diffText());
    }

    public function testContextCycleReloadsTheDiffWithMoreContextAndWrapsAround(): void
    {
        $controller = $this->controller();
        $body = implode("\n", array_map(static fn (int $i): string => 'line '.$i, range(1, end: 30)))."\n";
        $this->project->write('big.txt', $body);
        $this->project->git('add', '-A');
        $this->project->git('commit', '-q', '-m', 'big');
        $this->project->write('big.txt', str_replace('line 15', replace: 'line 15 EDITED', subject: $body));
        $controller->open();
        $this->await(fn (): bool => str_contains($this->diffText(), '+line 15 EDITED'));
        $this->assertStringNotContainsString('line 5 ', $this->diffText(), 'default context stops three lines out');

        $this->diffView->setFocused(true);
        $this->diffView->handleInput('c');
        $this->await(fn (): bool => str_contains($this->diffText(), 'line 5 '), what: '-U10 pulls in farther context');
        $this->assertStringContainsString('Diff context: ±10 lines', $this->statusRow($this->statusBar));
        $this->assertStringNotContainsString('line 1 ', $this->diffText());

        $this->diffView->handleInput('c');
        $this->await(fn (): bool => str_contains($this->diffText(), 'line 1 '), what: 'whole-file context starts at line 1');
        $this->assertStringContainsString('Diff context: whole file', $this->statusRow($this->statusBar));

        $this->diffView->handleInput('c'); // wraps back to the default
        $this->await(fn (): bool => !str_contains($this->diffText(), 'line 5 '));
        $this->assertStringContainsString('Diff context: default', $this->statusRow($this->statusBar));
    }

    public function testContextCycleGuardsUntrackedAndMissingDiffs(): void
    {
        $controller = $this->controller();
        $this->project->write('new.txt', "fresh\n");
        $controller->open();
        $this->await(fn (): bool => str_contains($this->diffText(), '+fresh'));

        // Untracked diffs are already whole-file: flash, no reload.
        $this->diffView->setFocused(true);
        $this->diffView->handleInput('c');
        $this->assertStringContainsString('Untracked files always show the whole file', $this->statusRow($this->statusBar));

        // No shown entry at all: a silent no-op.
        Probe::set($controller, 'shownEntry', null);
        Probe::invoke($controller, 'cycleContext');
        $this->assertStringContainsString('Untracked files', $this->statusRow($this->statusBar), 'no new flash replaced the old one');
    }

    public function testHistoryModeBrowsesCommitsAndShowsReadOnlyDiffs(): void
    {
        $controller = $this->controller();
        // A second commit so the log has two entries.
        $this->project->write('a.txt', "one\ntwo\nthree\n");
        $this->project->git('add', '-A');
        $this->project->git('commit', '-q', '-m', 'second');
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // switch to History
        $this->await(fn (): bool => null !== $this->history->selectedCommit());
        $this->assertSame(['git-tabs', 'git-history'], array_map(static fn ($w) => $w->getId(), $this->gitLeft->all()), 'the left pane swapped to the history list (tabs stay on top)');
        $this->assertSame('second', $this->history->selectedCommit()?->subject);
        $this->assertTrue(Probe::get($this->diffView, 'readOnly'));

        // The files of the newest commit load, and the FIRST file's patch
        // shows immediately — no keyboard step needed to see a diff.
        $this->await(fn (): bool => str_contains($this->historyText(), 'M a.txt'));
        $this->await(fn (): bool => str_contains($this->diffText(), '+three'), what: "the first file's patch auto-loads on commit selection");

        // Keyboard file browsing still refires loads.
        $this->history->setFocused(true);
        $this->history->handleInput("\r"); // into the file list
        $this->history->handleInput("\x1b[B");
        $this->history->handleInput("\x1b[A");
        $this->await(fn (): bool => str_contains($this->diffText(), '+three'), what: 'the commit file patch renders');

        // Staged keys are inert in read-only mode.
        $this->diffView->setFocused(true);
        $this->diffView->handleInput(' ');
        $this->assertSame([], $this->porcelain(), 'nothing staged from history');

        // Back to Changes: left pane restored, diff editable again.
        $this->history->setFocused(true);
        $this->history->handleInput('1');
        $this->await(fn (): bool => 3 === \count($this->gitLeft->all()));
        $this->assertFalse(Probe::get($this->diffView, 'readOnly'));
    }

    public function testRightClickSelectsAHistoryCommitForItsContextMenu(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "one\ntwo\nthree\n");
        $this->project->git('add', '-A');
        $this->project->git('commit', '-q', '-m', 'second');
        $controller->open();
        $this->await($controller->isOpen(...));

        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History
        $this->await(fn (): bool => null !== $this->history->selectedCommit());
        $this->history->render(new RenderContext(44, 20)); // set the row geometry

        $commit = $this->history->selectCommitAt(1); // the newest commit row
        $this->assertSame('second', $commit?->subject, 'a right-click selects the commit under it');
        $this->assertSame(1, $this->history->selectedCommitRow(), 'the newest commit sits on the first row');

        // Selecting a different row moves the selection (and fires the callback).
        $older = $this->history->selectCommitAt(2);
        $this->assertNotSame('second', $older?->subject, 'the second row is an older commit');
        $this->assertSame(2, $this->history->selectedCommitRow());

        $this->assertNull($this->history->selectCommitAt(0), 'the header row is no commit');
        $this->assertNull($this->history->selectCommitAt(3), 'an empty commit slot is no commit');
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
        $this->await(fn (): bool => str_contains($this->diffText(), 'No files in this commit'));
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

        // History-mode focus cycle: history <-> diff only.
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => 2 === \count($this->gitLeft->all()));
        $this->history->setFocused(true);
        $this->history->handleInput("\t");
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
        $this->awaitTicks(15);
        $this->assertFalse($controller->isOpen());
        $controller->open();

        $commit = $this->history->selectedCommit();
        // Fire the loaders, then flip back to Changes before they resolve:
        // both callbacks must drop their results.
        Probe::invoke($controller, 'loadCommitFiles', $commit);
        Probe::invoke($controller, 'loadCommitFileDiff', $commit, 'a.txt');
        $this->history->setFocused(true);
        $this->history->handleInput('1');
        $this->awaitTicks(15);
        $this->assertFalse(Probe::get($this->diffView, 'readOnly'), 'back on Changes, untouched by stale history data');
    }

    public function testMovingTheHistorySelectionLoadsWhatItLandsOn(): void
    {
        // Both loaders are private and were reached only through Probe. The
        // keyboard test above navigates and re-asserts the SAME patch, so it
        // passes whether or not the reload happened; emptying either listener
        // was invisible. This one makes every hop change what is on screen.
        $controller = $this->controller();
        file_put_contents($this->project->dir.'/a.txt', data: "one\ntwo\nthree\n");
        $this->project->commitAll('add three');
        $controller->open();
        $this->changes->setFocused(true);
        $this->changes->handleInput('2'); // History
        $this->await(fn (): bool => 2 === $this->history->commitCount());
        $this->await(fn (): bool => str_contains($this->diffText(), '+three'), what: 'the newest commit loads on arrival');

        // Down to the init commit: only GitCommitSelected can load its files,
        // and .gitignore sorts first, so its patch is what must appear.
        $this->history->setFocused(true);
        $this->history->handleInput("\x1b[B");
        $this->await(fn (): bool => str_contains($this->diffText(), '+.porta/'), what: "the older commit's first file loads");
        $this->assertStringContainsString('.gitignore', $this->historyText());

        // Into that commit's file list and down to a.txt: only
        // GitCommitFileSelected can swap the patch.
        $this->history->handleInput("\r");
        $this->history->handleInput("\x1b[B");
        $this->await(fn (): bool => str_contains($this->diffText(), '+two'), what: 'selecting a file loads that file');
        $this->assertStringNotContainsString('+.porta/', $this->diffText());
    }

    private function historyText(): string
    {
        return implode("\n", array_map(\Symfony\Component\Tui\Ansi\AnsiUtils::stripAnsiCodes(...), $this->history->render(new RenderContext(44, 20))));
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
        $this->commitBox->handleInput("\t"); // description -> next pane
        $this->assertSame($this->changes, $this->tui->getFocus());
    }

    public function testQuietRefreshSkipsRepaintWhenNothingChanged(): void
    {
        $controller = $this->controller();
        $this->project->write('a.txt', "poked\ntwo\n");
        $controller->open();
        $this->await(fn (): bool => null !== $this->changes->selectedEntry());

        $before = $this->changes->selectedEntry();
        $controller->refresh(RefreshMode::Quiet);
        $this->awaitTicks(10);
        $this->assertSame($before?->path, $this->changes->selectedEntry()?->path, 'identical status output leaves state alone');

        $controller->close();
        $controller->refresh(); // closed: the status callback drops its result
        $this->awaitTicks(10);
        $this->assertFalse($controller->isOpen());
    }

    private function diffText(): string
    {
        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $this->diffView->render(new RenderContext(80, 20))));
    }

    private function awaitTicks(int $ticks): void
    {
        for ($i = 0; $i < $ticks; ++$i) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.01, $suspension->resume(...));
            $suspension->suspend();
        }
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
        $this->awaitTicks(5);
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

        // A commit lands "remotely" (same repo here); the completed event reloads page 0.
        $this->project->git('commit', '-q', '--allow-empty', '-m', 'fromremote');
        $controller->onRemoteCompleted(new GitRemoteCompleted('pull', true));
        $this->await(fn (): bool => 'fromremote' === ($this->history->selectedCommit()->subject ?? ''), what: 'the newest commit appears at the top');
        $this->addToAssertionCount(1); // the throwing awaits above are the assertions
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
        $this->awaitTicks(10);
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
        $this->await(fn (): bool => str_contains($this->historyText(), 'init'));
        $this->assertStringNotContainsString('touch-b', $this->historyText());

        // Tabbing to Changes drops the filter; History reopens repo-wide.
        $this->history->handleInput('1');
        $this->await(fn (): bool => 3 === \count($this->gitLeft->all()));
        $this->changes->setFocused(true);
        $this->changes->handleInput('2');
        $this->await(fn (): bool => str_contains($this->historyText(), 'touch-b'), what: 'the filter is gone');
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
        $this->await(fn (): bool => str_contains($this->historyText(), 'History — a.txt'));
        $this->assertSame('touch-a', $this->history->selectedCommit()?->subject);
    }

    public function testFileHistoryOutsideARepositoryFlashesAndStaysClosed(): void
    {
        $controller = $this->controller(realRepo: Presence::Without);
        $controller->openFileHistory($this->project->path('a.txt'));
        $this->assertFalse($controller->isOpen());
        $this->assertStringContainsString('Not a git repository', $this->statusRow($this->statusBar));
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
