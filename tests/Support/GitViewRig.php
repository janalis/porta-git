<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Support;

use Porta\Config\LayoutStore;
use Porta\Editor\BufferManager;
use Porta\Editor\EditorPanes;
use Porta\Event\GitCommitted;
use Porta\GitPlugin\Command\GitCommands;
use Porta\GitPlugin\Command\GitRemoteCommands;
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
use Porta\GitPlugin\Event\GitStageAllRequested;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\Process\GitRemote;
use Porta\GitPlugin\Process\ProcessLane;
use Porta\GitPlugin\View\GitContextMenus;
use Porta\GitPlugin\View\GitMouseRouter;
use Porta\GitPlugin\View\GitPanes;
use Porta\GitPlugin\View\GitViewController;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\CommitDetailsWidget;
use Porta\GitPlugin\Widget\CommitHeaderWidget;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\GitToolbarWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Layout\LayoutController;
use Porta\Layout\PreviewSplit;
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
use Porta\Widget\TabBarWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\EditorArea;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewHost;
use Porta\Workbench\View\ViewRegistry;
use Porta\Workbench\View\ViewWindow;
use Revolt\EventLoop;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The git view, driven against a real repository with a real Tui on a virtual
 * terminal — no full Ide boot, so every controller branch is cheap to reach —
 * and the shims that ORDER two git answers. Shared by the controller's test
 * classes, which outgrew one file.
 */
trait GitViewRig
{
    use AwaitsGit;
    use GitBinary;
    use PumpsLoop;
    use StatusReads;

    /** Under .porta/, which the fixture gitignores — see {@see installShim()}. */
    private const string SHIM_RELEASE = '.porta/release-git';
    private const string SHIM_CAPTURED = '.porta/captured-git';

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
            $this->awaitLanes([Probe::get(Probe::get($this->git, 'runner'), 'lane'), $this->remoteLane]);
        }
    }

    private Tui $tui;

    /** The remote operations' own lane, drained at teardown like git's. */
    private ProcessLane $remoteLane;

    private GitRemoteCommands $remoteCommands;
    private ChangesListWidget $changes;
    private DiffViewWidget $diffView;
    private CommitBoxWidget $commitBox;
    private HistoryListWidget $history;
    private GitTabsWidget $tabs;

    private GitToolbarWidget $toolbar;

    private CommitDetailsWidget $details;

    private CommitHeaderWidget $header;
    private MenuBarWidget $menuBar;
    private ContainerWidget $gitLeft;
    private ViewRegion $region;
    private ContainerWidget $main;
    private StatusBarWidget $statusBar;
    private int $committed = 0;
    private CodeEditorWidget $editor;
    private LayoutController $layoutController;
    private EventDispatcher $dispatcher;

    /**
     * @param (\Closure(): ?string)|null $gitPath a live `git` path, for the two tests
     *                                            that force one call through a
     *                                            blocking shim to ORDER two answers
     */
    private function controller(Presence $realRepo = Presence::With, int $historyPageSize = 200, ?\Closure $gitPath = null): GitViewController
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
        $this->git = new GitInfo($this->project->dir, gitPath: $gitPath);

        $this->dispatcher = new EventDispatcher();
        $dispatcher = $this->dispatcher;
        $this->changes = new ChangesListWidget(new Translator('en'), events: $dispatcher);
        $this->diffView = new DiffViewWidget(new Translator('en'), events: $dispatcher);
        $this->commitBox = new CommitBoxWidget(new Translator('en'));
        $this->history = new HistoryListWidget(new Translator('en'), events: $dispatcher);
        $this->history->setId('git-history');
        $this->tabs = new GitTabsWidget(new Translator('en'), events: $dispatcher);
        $this->tabs->setId('git-tabs');
        $this->details = new CommitDetailsWidget(new Translator('en'), events: $dispatcher);
        $this->details->setId('git-commit-details');
        $this->header = new CommitHeaderWidget(new Translator('en'), events: $dispatcher);
        $this->header->setId('git-commit-header');
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
            new TabBarWidget(),
            // The controller declares its own sidebar when it mints the region,
            // so the layout gets it from there rather than being handed one.
            $regions,
            $t,
            new PreviewSplit(Styles::dark()),
        );

        // The region arrives already minted, exactly as ViewServices delivers
        // it: core mints it for the registered id before calling the factory.
        $region = $regions->mint('git');
        $host = new ViewHost(
            new ViewWindow($this->main),
            $this->menuBar,
            $editorArea,
            $this->tui,
        );
        $flash = GitDoubles::flash($notifier);
        $trans = GitDoubles::translate($t);
        $repaint = $this->tui->requestRender(...);
        $copy = new Clipboard($this->tui)->copy(...);
        $modals = GitDoubles::modals($this->editor);

        $repository = GitDoubles::repository($this->git, $this->project->dir);
        $this->toolbar = new GitToolbarWidget(new Translator('en'), $repository, events: $dispatcher);
        $controller = new GitViewController(
            $this->git,
            new ProjectPaths($this->project->dir),
            new GitPanes($this->changes, $this->diffView, $this->commitBox, $this->history, $this->tabs, $this->toolbar, $this->details, $this->header),
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
                    static fn (): array => [],
                    static function (array $lines, string $title): void {},
                    GitDoubles::worktrees($this->git, \sys_get_temp_dir()),
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
            $this->remoteCommands = new GitRemoteCommands(new GitRemote($this->project->dir, lane: $this->remoteLane = new ProcessLane()), $flash, $trans, $dispatcher, $modals, $repaint),
            historyPageSize: $historyPageSize,
            repository: $repository,
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
        $dispatcher->addListener(GitStageAllRequested::class, $controller->onStageAllRequested(...));
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
    /**
     * The index as git sees it. --no-optional-locks, as every reader the plugin
     * runs: this is polled by awaits WHILE the plugin mutates the index, and a
     * plain `git status` takes index.lock to refresh stat data — under load it
     * held the lock at the moment the plugin's `git reset` needed it, and the
     * unstage failed ("git refused the staging change") one run in ~80.
     */
    private function porcelain(): array
    {
        \exec('git --no-optional-locks -C '.\escapeshellarg($this->project->dir).' status --porcelain 2>/dev/null', $lines);

        return $lines;
    }

    /**
     * A shim `git` that runs NOW and then holds its bytes until released: the
     * caller gets a genuinely OLD answer, late. The stale `git status`
     * snapshot.
     *
     * Pair it with {@see awaitCapture()} — proc_open returning is not the
     * child having run.
     *
     * @param-out string $shim
     */
    private function gitHoldingItsAnswer(?string &$shim): \Closure
    {
        return $this->installShim(
            $shim,
            'tmp=$(mktemp)'
            ."\n"
            .$this->gitBinary()
            .' "$@" > "$tmp"'
            ."\n"
            .'rc=$?'
            ."\n"
            .'touch '
            .\escapeshellarg($this->project->path(self::SHIM_CAPTURED))
            ."\n"
            .$this->waitForRelease()
            ."\n"
            .'cat "$tmp"'
            ."\n"
            .'rm -f "$tmp"'
            ."\n"
            .'exit $rc',
        );
    }

    /**
     * A shim `git` that holds the CALL and runs only once released: an old
     * request answered against today's tree. The stale `git diff` SIDE, chosen
     * from an entry that has since been staged.
     *
     * The distinction from {@see gitHoldingItsAnswer()} is not cosmetic. Using
     * this one for the status race is not a failing test but a passing one: a
     * held-then-run `git status` returns the post-staging porcelain and races
     * nothing at all.
     *
     * @param-out string $shim
     */
    private function gitHoldingItsCall(?string &$shim): \Closure
    {
        return $this->installShim($shim, $this->waitForRelease()."\n".'exec '.$this->gitBinary().' "$@"');
    }

    /**
     * Write $script as the project's shim `git` and return the closure that
     * releases whatever it is holding.
     *
     * Blocking the PROCESS rather than sleeping in the test is what makes the
     * ordering a fact and not another timing bet: the older answer cannot
     * arrive until the newer one already has, whatever the machine is doing.
     *
     * @param-out string $shim
     */
    private function installShim(?string &$shim, string $script): \Closure
    {
        // Under .porta/, which this fixture gitignores: a shim script sitting
        // in the worktree would show up as `?? ` in the very porcelain these
        // tests compare.
        $shim = $this->project->write('.porta/shim/git', '#!/bin/sh'."\n".$script."\n");
        \chmod($shim, permissions: 0o755);
        $project = $this->project;

        return static function () use ($project): void {
            \touch($project->path(self::SHIM_RELEASE));
        };
    }

    /** Single-quoted on purpose: PHP would eat $tmp and $rc out of these scripts. */
    private function gitBinary(): string
    {
        return \escapeshellarg((string) $this->whichGit());
    }

    private function waitForRelease(): string
    {
        return 'while [ ! -f '.\escapeshellarg($this->project->path(self::SHIM_RELEASE)).' ]; do sleep 0.02; done';
    }

    /**
     * Wait until the capture shim has actually RUN git, not merely been spawned.
     *
     * proc_open returns as soon as the child exists; the child is a /bin/sh that
     * still has to be scheduled before it reaches its `git status` line. Without
     * this barrier the capture happened after the test's next `git add` and the
     * "stale" answer carried today's porcelain — a test that passes against the
     * bug, which is how it read the first time.
     */
    private function awaitCapture(): void
    {
        $marker = $this->project->path(self::SHIM_CAPTURED);
        $this->await(static fn (): bool => \is_file($marker), what: 'the held status to capture its answer');
    }

    /**
     * Stop the 2s status poll from healing the damage a test is looking for.
     *
     * Disabled rather than cancelled so tearDown's close() still has a
     * callback to cancel. The poll is not what these two tests are about: a
     * refresh landing between the stale answer and the assertion would repair
     * the pane and read as a pass.
     */
    private function freezeStatusPoll(GitViewController $controller): void
    {
        EventLoop::disable((string) Probe::get($controller, 'poll'));
    }

    /** The one lane every git call in this test class runs through. */
    private function lane(): ProcessLane
    {
        return Probe::get(Probe::get($this->git, 'runner'), 'lane');
    }

    private function historyText(): string
    {
        return \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->history->render(new RenderContext(44, 20))));
    }

    private function detailsText(): string
    {
        // History's middle: the commit header over the files.
        return \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), [...$this->header->render(new RenderContext(44, 3)), ...$this->details->render(new RenderContext(44, 20))]));
    }

    private function diffText(): string
    {
        return \implode("\n", \array_map(AnsiUtils::stripAnsiCodes(...), $this->diffView->render(new RenderContext(80, 20))));
    }

    private function toolbarText(): string
    {
        return AnsiUtils::stripAnsiCodes(\implode("\n", $this->toolbar->render(new RenderContext(100, 3))));
    }
}
