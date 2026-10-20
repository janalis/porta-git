<?php

declare(strict_types=1);

use Porta\Event\ChangeProposed;
use Porta\Event\DiffToolLaunched;
use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\Event\MergeToolLaunched;
use Porta\Event\PluginObservable;
use Porta\Event\QuitRequested;
use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\Blame\BlameAnnotations;
use Porta\GitPlugin\Blame\BlameClient;
use Porta\GitPlugin\Blame\BlameLine;
use Porta\GitPlugin\BranchMenu;
use Porta\GitPlugin\ChangeMarks;
use Porta\GitPlugin\ChangeView;
use Porta\GitPlugin\Command\GitCommands;
use Porta\GitPlugin\Command\GitRemoteCommands;
use Porta\GitPlugin\Command\IndexCommands;
use Porta\GitPlugin\Command\Stash;
use Porta\GitPlugin\Command\WorktreeCommands;
use Porta\GitPlugin\GitBranchSegment;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitMenu;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\GitTools;
use Porta\GitPlugin\Process\GitProcesses;
use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\GitPlugin\Process\GitRemote;
use Porta\GitPlugin\Process\ProcessLane;
use Porta\GitPlugin\Process\PullRequestLookup;
use Porta\GitPlugin\RepositoryState;
use Porta\GitPlugin\Setting\DiffPrefs;
use Porta\GitPlugin\Setting\GitSettings;
use Porta\GitPlugin\Setting\LegacySettings;
use Porta\GitPlugin\Support\Flash;
use Porta\GitPlugin\Support\Modals;
use Porta\GitPlugin\Support\Translate;
use Porta\GitPlugin\Support\ViewSlot;
use Porta\GitPlugin\View\GitContextMenus;
use Porta\GitPlugin\View\GitMouseRouter;
use Porta\GitPlugin\View\GitPanes;
use Porta\GitPlugin\View\GitViewController;
use Porta\GitPlugin\View\GitWiring;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\CommitDetailsWidget;
use Porta\GitPlugin\Widget\CommitHeaderWidget;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\GitToolbarWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\GitPlugin\WorkingTreeStatus;
use Porta\GitPlugin\Worktree;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\DisposablePlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\ProjectPaths;
use Porta\Workbench\Menu\MenuSurface;
use Porta\Workbench\View\ViewServices;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Tui\Style\Style;

/**
 * Git, in full: the GitHub-Desktop-style view, the branch indicator, the Git
 * menu, the palette verbs, the gutter stripe and blame.
 *
 * All of it used to be `src/Git`, `src/Widget/Git`, a command provider, a menu
 * group, a status-bar branch and a settings page — 5,700 lines of core naming
 * one version control system. `docs/internals/decisions.md` recorded an
 * attempt that stopped at the view and said why; every one of those reasons
 * was a missing seam, and Phase A built them. What is left here is the wiring
 * those seams need, which is the honest cost of a plugin owning a workbench
 * view.
 *
 * The shape is forced by one rule: **a plugin class can never be a container
 * service**, because the container is compiled and cached before any plugin PHP
 * runs. Nothing below is autowired. Every object is built here, in order, and
 * everything it needs from core arrives either on `PluginApi` or — for the
 * view's own collaborators — on the `ViewServices` core hands the factory.
 *
 * Two construction phases, and the split is not cosmetic:
 *
 * - **activate()** builds what exists whether or not the view is ever opened:
 *   the repository handle, the settings holder, the commands the palette and
 *   the menu name, the branch segment. These may not touch the IDE runtime yet
 *   — `PluginApi` refuses during `activate()` — so every one of them takes a
 *   closure it calls later.
 * - **the view factory** builds the widgets, the pointer and the controller.
 *   It runs once, after plugins have committed, with the region already minted
 *   and the `ViewHost` in hand. Nothing here can exist earlier: a widget needs
 *   `Styles`, and a pointer needs the `HitTester`.
 *
 * The fourteen widget→controller events travel on a dispatcher owned by this
 * file. They were `Porta\Event\Git*` on core's dispatcher with
 * `#[AsEventListener]` methods, and both halves evaporate outside `src/`. Only
 * `GitCommitted`, `GitRemoteCompleted` and `WorkingTreeChanged` stay core's —
 * they are the three anything else may care about, so they are `PluginObservable`.
 *
 * Outside a repository the plugin still loads and still registers blame's
 * verbs; it registers no view, no menu and no branch cell, so the chrome is
 * simply absent rather than present and dead.
 *
 * ## Where a class goes
 *
 * `src/` has `docs/internals/code-map.md` for this. No other plugin is large
 * enough to need one; this one is four times the next biggest, so:
 *
 * - **the root** — what the plugin is from outside: `GitInfo`, the branch
 *   segment, the menu, the refresher, the change marks, and the two value
 *   objects both halves pass around. If core or the other half names it, it
 *   belongs here.
 * - **`Process/`** — subprocesses and their tracking. A `ProcessLane` is a
 *   cancellation scope, NOT a queue: nothing serialises, and the two lanes
 *   exist because "Cancel Remote Operation" must stop a fetch without stopping
 *   the status poll.
 * - **`Parse/`** — git's output in, values out. Pure functions; a parser that
 *   needs a collaborator is in the wrong tier.
 * - **`Diff/`** — the diff model and the algorithms over it. Render geometry
 *   (`DiffColumn`, `DiffGeometry`, `DiffCharSelection`) is in `Widget/`,
 *   because it is about columns on a screen rather than about a diff.
 * - **`View/`** — everything that only exists while the view is open.
 * - **`Command/`** — the verbs the palette, the menu and the context menus
 *   name, plus their modes. Two command objects on purpose: the view's and the
 *   editor half's, which works without a view.
 * - **`Blame/`**, **`Setting/`** — one feature each.
 * - **`Support/`** — the closure shims (`Flash`, `Modals`, `Translate`) and
 *   `ViewSlot`. Nothing here is git; each is a core service a plugin cannot
 *   hold, behind the method name its call sites already used.
 * - **`Widget/`**, **`Event/`** — as in `src/`: things that render, and values
 *   that travel on the dispatcher above.
 */
return new class('git') extends AbstractPlugin implements DisposablePlugin {
    /** The dock tab a file comparison lands in — the plugin's own bare id. */
    private const string COMPARE_TOOL = 'compare';

    /** Held for {@see deactivate()}: the lanes outlive every registration. */
    private ?GitProcesses $processes = null;

    /** Held so a `blameDefault` edit reaches the toggle state it seeds. */
    private ?BlameAnnotations $annotations = null;

    /**
     * A live disable strips the listener set — including the onShutdown hook
     * below — so without this the process lanes (and any in-flight fetch,
     * blame or status poll callback) would be orphaned in a still-running IDE.
     * The view's own repeat timer is not this hook's job: it belongs to
     * {@see GitViewController::close()}, which runs when the view leaves the
     * workspace.
     */
    public function deactivate(): void
    {
        $this->processes?->cancelAll();
    }

    public function activate(PluginApi $api): void
    {
        $this->registerSettings($api);
        $root = $api->projectRoot();
        // These eight were CORE settings until git became a plugin, keyed
        // `gitShowBranch` and friends at the top level of `.porta.json`. A user
        // who turned one off before the move keeps it off.
        $legacy = LegacySettings::forProject($root);
        $settings = new GitSettings($legacy->seed($api->setting(...)));
        // A CLOSURE over the holder, not a resolved string: every runner below
        // asks per spawn, so editing the `path` setting reaches the next git
        // command with no restart. '' means "discover", which the runners spell
        // as null.
        $gitPath = static fn (): ?string => '' === $settings->path ? null : $settings->path;
        // Write whatever was adopted through, so the file stops answering the
        // same question twice and the settings page shows what is in force.
        // From onReady, because persisting is an IDE action and activate() may
        // take none.
        $api->onReady(static function () use ($api, $legacy): void {
            foreach ($legacy->adoptions() as $key => $value) {
                $api->changeSetting($key, $value);
            }
        });

        // ONE handle on git and ONE set of process lanes, shared by both halves.
        // The editor half used to carry its own `GitClient` and its own runner,
        // because it was written when the halves lived in different namespaces
        // and this object was genuinely unreachable.
        $processes = new GitProcesses();
        $this->processes = $processes;
        $git = new GitInfo($root, $settings, $gitPath, $processes->general);
        // Shutdown stops BOTH lanes. It used to stop one of the four the plugin
        // had, so a fetch, a blame or a mark refresh could outlive the loop.
        $api->onShutdown($processes->cancelAll(...));

        // Blame and the gutter stripe: they own no widget and no region, so
        // they were extractable long before the rest.
        $this->activateBlameAndMarks($api, $settings, $gitPath, $git, $processes);

        if (!$git->isRepository()) {
            // Every verb below acts on a repository. `when` would not do: it
            // gates KEY dispatch only, leaving the palette and the menus
            // running a handler that can only flash. Not registering removes
            // them from all three at once, and the menu group goes with them.
            //
            // Fixed at load, like the settings that carry a `restart` note:
            // `git init` in a running IDE needs a restart to light the chrome.
            return;
        }
        // For agents, through whatever serves registered tools; on the general
        // lane, so shutdown and the completion barrier reach these too.
        new GitTools($api, new GitProcessRunner($root, $gitPath, $processes->general))->register();

        // Deferred on purpose: PluginApi refuses every IDE action during
        // activate(), so each of these is a closure the callers invoke later.
        $flash = new Flash($api->notify(...));
        $modals = new Modals($api->pick(...), $api->prompt(...), $api->activeFile(...));
        $repaint = $api->refreshStatusSegments(...);
        $trans = new Translate($api->trans(...));
        $events = new EventDispatcher();
        // Three of the events this plugin dispatches are CORE facts —
        // `PluginObservable`, the frozen set anything may observe — and they go
        // out on the plugin's own bus like everything else, because the code
        // that raises them cannot tell the difference. So the bus forwards them
        // to core's, which is what keeps the gutter stripe refreshing after a
        // commit and a pull. Without this they would reach only this plugin's
        // own listeners, and nothing would say so.
        foreach ([GitCommitted::class, GitRemoteCompleted::class, WorkingTreeChanged::class] as $fact) {
            $events->addListener($fact, static function (PluginObservable $event) use ($api): void {
                $api->emit($event);
            });
        }

        $refresher = new GitRefresher($git, $repaint);
        $worktrees = new WorktreeCommands(
            $git,
            $modals,
            $flash,
            $repaint,
            $trans,
            $api->openProject(...),
            static fn (): string => (string) $api->setting('worktreeFolder'),
            $root,
        );
        $commands = new GitCommands(
            $git,
            $modals,
            $flash,
            $repaint,
            $trans,
            $refresher,
            $api->copyToClipboard(...),
            $events,
            $api->selectedFiles(...),
            static function (array $lines, string $title) use ($api): void {
                $rows = [];
                foreach ($lines as $line) {
                    // Location-less: a diff line has no place to jump to, and
                    // the panel prints no `file:line` rather than inventing one.
                    $rows[] = ['path' => '', 'line' => 0, 'text' => $line];
                }
                $api->showList(self::COMPARE_TOOL, $rows);
                $api->flash($title);
            },
            $worktrees,
        );
        $remote = new GitRemoteCommands(new GitRemote($root, $gitPath, $processes->remote), $flash, $trans, $events, $modals, $repaint);

        $api->registerListTool(self::COMPARE_TOOL, 'Compare');
        $this->registerCommands($api, $commands, $remote);
        $api->registerCommand('worktrees', 'Git: Worktrees…', $worktrees->worktrees(...));
        $api->registerCommand('new_worktree', 'Git: New Worktree…', $worktrees->newWorktree(...));
        // On the tree row's own right-click, where a pair is built — dimmed
        // until there are exactly two, which is the command's precondition
        // said in the surface rather than only in a flash afterwards.
        $api->addMenuItem('git_compare_files', static fn (): bool => 2 === \count($api->selectedFiles()), MenuSurface::FileContext);
        $api->registerMenu(
            title: 'Git',
            after: GitMenu::AFTER,
            items: GitMenu::items(),
            viewId: 'git',
            viewSafe: GitMenu::viewSafe(),
        );

        $branchMenu = self::registerBranchCell($api, $refresher, $settings, new BranchMenu($commands, $worktrees, $trans), $trans);
        $repository = self::repositoryState($git, $root, $processes->general);

        // The view is built LATER — its factory runs after plugins have
        // committed — but every listener has to be registered now, because the
        // buffer is drained at commit and a later `on()` is refused. So the
        // things that reach the view go up front over a holder the factory
        // fills, and fire on nothing until it does.
        $slot = new ViewSlot();

        // The settings page writes the value; this is what makes it visible
        // without a restart. Two apply LIVE, and they are the two a user
        // toggles to see what happens: the branch cell and the gutter stripe.
        // The rest only have to reach the holder, because every reader of them
        // reads at the moment it acts — a commit reads signoff when it commits,
        // and `path` is resolved by the runners on the next spawn.
        $api->onSettingChanged(function (string $key, bool|int|string $value) use ($api, $settings, $repaint, $slot): void {
            // The holder first, always: every reader takes its value from here
            // at the moment it acts, so a commit picks up `signoff` on the next
            // commit without anything else happening.
            match ($key) {
                'showBranch' => $settings->showBranch = (bool) $value,
                'gutterMarks' => $settings->gutterMarks = (bool) $value,
                'blameDefault' => $settings->blameDefault = (bool) $value,
                'signCommits' => $settings->signCommits = (bool) $value,
                'noVerify' => $settings->noVerify = (bool) $value,
                'signoff' => $settings->signoff = (bool) $value,
                'refreshInterval' => $settings->refreshInterval = (int) $value,
                'path' => $settings->path = (string) $value,
                default => null, // the two diff toggles the view writes itself
            };
            // `blameDefault` seeds a toggle whose live state lives in the
            // annotations object, so reaching the holder is not enough — that
            // is what made its setter a write-only mutator with no readers.
            if ('blameDefault' === $key) {
                $this->annotations?->setEnabled((bool) $value);
            }
            // The poll interval is baked into an EventLoop::repeat when the
            // view opens, so an edit made with the view UP needs it re-armed;
            // armPoll() is a no-op while it is closed.
            if ('refreshInterval' === $key) {
                $slot->view?->armPoll();
            }
            // Then the two that also have to SHOW immediately, which are the
            // two a user toggles to see what happens. The branch cell is pulled
            // per render, so it only needs the bar told it is stale; the gutter
            // strip is a layout width, so it needs the verb.
            if ('showBranch' === $key) {
                $repaint();
            }
            if ('gutterMarks' === $key) {
                $api->showChangeMarkGutter((bool) $value);
            }
        });

        // Toggle, not open: Ctrl+Alt+D has always closed the view it opened.
        // One call, because "is it open?" is the registry's question — asking
        // it here would mean keeping the controller alive to read one bool,
        // and the controller does not exist yet.
        $api->adoptCommand('ide_git', static fn () => $api->toggleView('git'));
        foreach (GitWiring::VIEW_CORE as $event => $method) {
            $api->on($event, static function (PluginObservable $fact) use ($slot, $method): void {
                // @mago-expect analysis:string-member-selector -- the method name comes from
                // GitWiring, a const map GitListenerContractTest pins to real methods.
                $slot->view?->{$method}($fact);
            });
        }
        $this->answerComparisons($api, $slot);
        $api->registerView(
            'git',
            fn (ViewServices $services): GitViewController => $slot->view = $this->buildView($api, $services, $git, $settings, $events, $flash, $commands, $remote, $branchMenu, $repository),
        );

        $api->onReady($refresher->refreshBranch(...));
        // A save, a commit, a completed fetch or pull, or anything else that
        // moved the working tree: the branch indicator may be stale.
        $api->onFileSaved(static fn (string $path) => $refresher->refreshBranch());
        foreach (GitWiring::REFRESHER as $event => $method) {
            // @mago-expect analysis:less-specific-nested-argument-type -- same const map, same
            // contract test. A forwarding closure only trades this for the string-member-selector
            // above. Re-checked 2026-09-02.
            $api->on($event, \Closure::fromCallable([$refresher, $method]));
        }
    }

    /**
     * The view's own half: five widgets, the pointer that hit-tests them, the
     * context menus they offer, and the controller that owns the lot.
     *
     * Deferred to a factory because none of it can be built during activate():
     * a widget needs `Styles` and a `CursorStyle`, a pointer needs the
     * `HitTester`, and the controller needs the region core minted for the id
     * this plugin registered under. That is what `ViewServices` carries, and
     * the reason the DTO exists at all.
     */
    /**
     * The status bar's branch cell, a contributed segment carrying its own
     * menu — the menu the view's toolbar drops too, so it is handed back.
     */
    private static function registerBranchCell(PluginApi $api, GitRefresher $refresher, GitSettings $settings, BranchMenu $menu, Translate $trans): BranchMenu
    {
        $api->registerStatusSegment(new GitBranchSegment($refresher, $settings, $menu, $trans, Worktree::linkedName($api->projectRoot()))->statusCell(...));

        return $menu;
    }

    /**
     * What the view's toolbar reads. `gh` is looked up per ask, so installing
     * it needs no restart, and a missing one is simply no pull request.
     */
    private static function repositoryState(GitInfo $git, string $root, ProcessLane $lane): RepositoryState
    {
        return new RepositoryState($git, new PullRequestLookup($root, static fn (): ?string => new ExecutableFinder()->find('gh'), $lane), $root);
    }

    /**
     * The asks this view answers from elsewhere: Porta launched as git's
     * difftool or mergetool, and an agent's proposed change to compare.
     */
    private function answerComparisons(PluginApi $api, ViewSlot $slot): void
    {
        // Porta launched as git's difftool or mergetool: this view is the answer.
        // A difftool comparison ends the session when it closes — git is waiting
        // on the process before it moves to the next file. A merge opens the
        // conflicted file in the editor UNDER the view, so Esc lands on its
        // markers; core decides the exit code from what is left in the file.
        $api->on(DiffToolLaunched::class, static function (PluginObservable $launch) use ($api, $slot): void {
            \assert($launch instanceof DiffToolLaunched, description: 'registered for this class only');
            $slot->view?->openComparison($launch->left, $launch->right, static fn () => $api->emit(new QuitRequested()));
        });
        // An agent's proposed change, side by side with the file it would
        // replace — read-only, and closing it ends nothing but the view.
        $api->on(ChangeProposed::class, static function (PluginObservable $proposal) use ($slot): void {
            \assert($proposal instanceof ChangeProposed, description: 'registered for this class only');
            $slot->view?->openComparison($proposal->path, $proposal->proposedPath, static function (): void {});
        });
        $api->on(MergeToolLaunched::class, static function (PluginObservable $launch) use ($api, $slot): void {
            \assert($launch instanceof MergeToolLaunched, description: 'registered for this class only');
            $api->openFile($launch->merged);
            $slot->view?->openOn($launch->merged);
            $api->flash($api->trans('Esc to edit the file · F7 next conflict · Alt+M resolve · Ctrl+S, then Ctrl+Q — conflicts left mean the merge fails'), 8.0);
        });
    }

    private function buildView(
        PluginApi $api,
        ViewServices $s,
        GitInfo $git,
        GitSettings $settings,
        EventDispatcher $events,
        Flash $flash,
        GitCommands $commands,
        GitRemoteCommands $remote,
        BranchMenu $branchMenu,
        RepositoryState $repository,
    ): GitViewController {
        $panes = new GitPanes(
            new ChangesListWidget($s->translator, styles: $s->styles, density: $s->density, events: $events),
            new DiffViewWidget($s->translator, $s->styles, $api->syntaxTheme(), $events),
            new CommitBoxWidget($s->translator, styles: $s->styles, cursorStyle: $s->cursorStyle, density: $s->density),
            new HistoryListWidget($s->translator, styles: $s->styles, events: $events),
            new GitTabsWidget($s->translator, styles: $s->styles, density: $s->density, events: $events),
            new GitToolbarWidget($s->translator, $repository, $s->styles, $s->density, $events),
            new CommitDetailsWidget($s->translator, $s->styles, $events),
            new CommitHeaderWidget($s->translator, $s->styles, $s->density, $events),
        );
        // The ids and flex styles the container used to apply from #[AsWidget].
        // The ids are how the renderer and every rect-reading test name these
        // widgets; the flex values are what makes the diff pane take the slack
        // while the three stacked sidebar widgets keep their rendered height.
        $panes->changes->setId('git-changes')->setStyle(new Style(flex: 0));
        // Beside History's files the split sizes it; alone in Changes, it takes the column.
        $panes->diffView->setId('git-diff')->setStyle(new Style(flex: 1));
        $panes->commitBox->setId('git-commit');
        $panes->history->setId('git-history');
        $panes->tabs->setId('git-tabs')->setStyle(new Style(flex: 0));
        $panes->toolbar->setId('git-toolbar');
        $panes->details->setId('git-commit-details');
        $panes->header->setId('git-commit-header')->setStyle(new Style(flex: 0));

        $controller = new GitViewController(
            $git,
            new ProjectPaths($api->projectRoot()),
            $panes,
            $s->region,
            $s->host,
            new GitMouseRouter($s->hit, $s->host, $panes->changes, $panes->diffView, $panes->commitBox, $panes->history, $panes->tabs, $panes->toolbar, $panes->details, $panes->header),
            new GitContextMenus($s->hit, $s->host, $panes->changes, $panes->history, $commands, $s->translator, $panes->toolbar, $branchMenu, $panes->diffView),
            $flash,
            $s->translator,
            $events,
            new DiffPrefs($api->setting(...), $api->changeSetting(...)),
            $api->copyToClipboard(...),
            $remote,
            gitSettings: $settings,
            repository: $repository,
        );
        $this->wireViewEvents($events, $controller);

        return $controller;
    }

    /**
     * The fourteen widget → controller listeners, read off {@see GitWiring} so
     * the map and the registration cannot drift.
     *
     * The fifteenth — core's `GitRemoteCompleted` — is registered in
     * `activate()` instead, because that is the only phase `PluginApi::on()`
     * accepts.
     */
    private function wireViewEvents(EventDispatcher $events, GitViewController $controller): void
    {
        foreach (GitWiring::VIEW as $event => $method) {
            // @mago-expect analysis:less-specific-nested-argument-type -- as above.
            $events->addListener($event, \Closure::fromCallable([$controller, $method]));
        }
    }

    /** The nine palette verbs, keeping the ids and labels core's ActionCatalog still names. */
    private function registerCommands(PluginApi $api, GitCommands $commands, GitRemoteCommands $remote): void
    {
        $api->adoptCommand('git_commit', $commands->commit(...));
        $api->adoptCommand('git_branches', $commands->branches(...));
        $api->adoptCommand('git_stashes', $commands->stashes(...));
        $api->adoptCommand('git_file_history', $commands->fileHistory(...));
        $api->adoptCommand('git_compare_files', $commands->compareFiles(...));
        $api->adoptCommand('git_fetch', $remote->fetch(...));
        $api->adoptCommand('git_pull', $remote->pull(...));
        $api->adoptCommand('git_push', $remote->push(...));
        $api->adoptCommand('git_push_upstream', $remote->pushSetUpstream(...));
        $api->adoptCommand('git_remote_cancel', $remote->cancelOperation(...));
    }

    /**
     * The eight settings, on this plugin's own page under Plugins.
     *
     * They were a core "Version control > Git" section keyed `gitShowBranch`,
     * `gitPath` and so on at the top level of `.porta.json`. They live under
     * `plugins.git.*` now, which is what every other plugin's settings do —
     * so the compat read below is the one irreversible part of this move.
     */
    private function registerSettings(PluginApi $api): void
    {
        $api->registerSetting('showBranch', 'Show branch in status bar', 'bool', true);
        $api->registerSetting('gutterMarks', 'Change marks in the gutter', 'bool', true);
        $api->registerSetting('blameDefault', 'Show blame annotations by default', 'bool', false);
        $api->registerSetting('signCommits', 'Sign commits (GPG)', 'bool', false);
        $api->registerSetting('noVerify', 'Skip commit hooks (--no-verify)', 'bool', false);
        $api->registerSetting('signoff', 'Add Signed-off-by trailer', 'bool', false);
        $api->registerSetting('refreshInterval', 'Status refresh interval (seconds)', 'int', 2, min: 1, max: 3_600);
        $api->registerSetting('path', 'Git executable path', 'text', '', hint: 'auto-detect');
        $api->registerSetting('worktreeFolder', 'Folder for new worktrees', 'text', '', hint: 'beside the main worktree');
        // The diff pane's two display toggles. Not on the page — the view sets
        // them from its own toolbar — but registered so they persist and so
        // reading one before it has ever been written yields a default.
        $api->registerSetting('diffSplit', 'Diff: side-by-side', 'bool', false);
        $api->registerSetting('diffHideWhitespace', 'Diff: hide whitespace changes', 'bool', false);
    }

    /** Blame annotations and the gutter stripe — the half that never needed the workbench. */
    /** @param \Closure(): ?string $gitPath resolved per spawn, so the `path` setting is live */
    private function activateBlameAndMarks(PluginApi $api, GitSettings $settings, \Closure $gitPath, GitInfo $git, GitProcesses $processes): void
    {
        // Built once, not per call: BlameAnnotations is a toggle, so it has to
        // remember whether it is on between the command and the hooks.
        $blame = new BlameClient($api->projectRoot(), $gitPath, $processes->general);
        $annotations = new BlameAnnotations($blame, $api, $settings->blameDefault);
        $this->annotations = $annotations;

        $api->adoptCommand('git_blame', new BlameLine($blame, $api)->show(...));
        $api->adoptCommand('git_toggle_blame', $annotations->toggle(...));

        $api->onBufferSwitched(static fn (string $path) => $annotations->refreshIfEnabled());
        // refreshIfEnabled() takes no path; the arrow function is where "the saved file is deliberately ignored" is visible.
        $api->onFileSaved(static fn (string $path) => $annotations->refreshIfEnabled());

        $marks = new ChangeMarks($git, $api, static fn (): bool => $settings->gutterMarks);
        $api->adoptCommand('git_show_change', new ChangeView($git, $api)->show(...));
        // The file tree's whole-repository marks. Only on things that move the
        // working tree — a save, and the three facts below (commit, remote,
        // stage/discard). Never on a buffer switch: nothing changed on disk.
        $treeStatus = new WorkingTreeStatus($git, $api, $api->projectRoot());
        $api->onReady($treeStatus->refresh(...));
        $api->onFileSaved(static fn (string $path) => $treeStatus->refresh());
        foreach ([GitCommitted::class, GitRemoteCompleted::class, WorkingTreeChanged::class] as $moved) {
            $api->on($moved, static fn (object $event) => $treeStatus->refresh());
        }
        $stash = new Stash($git, $api);
        $index = new IndexCommands($git, $api);
        $api->adoptCommand('git_stage', $index->stageCurrent(...));
        $api->adoptCommand('git_undo_commit', $index->undoLastCommit(...));
        $api->adoptCommand('git_stash', $stash->push(...));
        $api->adoptCommand('git_stash_pop', $stash->pop(...));

        // From onReady, not here: PluginApi refuses every IDE action during
        // activate().
        $api->onReady(static fn () => $api->showChangeMarkGutter($settings->gutterMarks));
        // No onFileOpened wiring: a fresh open also fires the deduped
        // buffer-switch (the blame annotations above rely on exactly that),
        // so subscribing to both spawned two identical `git diff` processes
        // per open. The switch hook alone covers every open that lands in
        // the editor; a viewer pane has no gutter to mark.
        $api->onFileSaved($marks->refresh(...));
        $api->onFileReloaded($marks->refresh(...));
        $api->onBufferSwitched($marks->refresh(...));
        // A commit, a stash, a pull: whatever moved, the marks are stale.
        // Not `$marks->refresh(...)`, which mago suggests and which would be a
        // TypeError: refresh() takes an optional ?string $path, so the event
        // object would land in it.
        // NOT first-class callables: the listener receives the event, and
        // refresh(?string $path) would take it as its path — a TypeError.
        // The arrow fns exist to DROP the argument.
        // @mago-expect lint:prefer-first-class-callable
        $api->on(WorkingTreeChanged::class, static fn () => $marks->refresh());
        // @mago-expect lint:prefer-first-class-callable
        $api->on(GitCommitted::class, static fn () => $marks->refresh());
        // @mago-expect lint:prefer-first-class-callable
        $api->on(GitRemoteCompleted::class, static fn () => $marks->refresh());
    }
};
