<?php

declare(strict_types=1);

use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\Event\PluginObservable;
use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\BlameAnnotations;
use Porta\GitPlugin\BlameClient;
use Porta\GitPlugin\BlameLine;
use Porta\GitPlugin\ChangeMarks;
use Porta\GitPlugin\DiffPrefs;
use Porta\GitPlugin\Flash;
use Porta\GitPlugin\GitBranchSegment;
use Porta\GitPlugin\GitClient;
use Porta\GitPlugin\GitCommands;
use Porta\GitPlugin\GitContextMenus;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitMenu;
use Porta\GitPlugin\GitMouseRouter;
use Porta\GitPlugin\GitPanes;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\GitRemote;
use Porta\GitPlugin\GitRemoteCommands;
use Porta\GitPlugin\GitSettings;
use Porta\GitPlugin\GitViewController;
use Porta\GitPlugin\GitWiring;
use Porta\GitPlugin\IndexCommands;
use Porta\GitPlugin\LegacySettings;
use Porta\GitPlugin\Modals;
use Porta\GitPlugin\Stash;
use Porta\GitPlugin\Translate;
use Porta\GitPlugin\ViewSlot;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Workbench\View\ViewServices;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
 */
return new class('git') extends AbstractPlugin {
    public function activate(PluginApi $api): void
    {
        $this->registerSettings($api);
        $root = $api->projectRoot();
        // These eight were CORE settings until git became a plugin, keyed
        // `gitShowBranch` and friends at the top level of `.porta.json`. A user
        // who turned one off before the move keeps it off.
        $legacy = new LegacySettings($root);
        $settings = new GitSettings($legacy->seed($api->setting(...)));
        $gitPath = $settings->path();
        // Write whatever was adopted through, so the file stops answering the
        // same question twice and the settings page shows what is in force.
        // From onReady, because persisting is an IDE action and activate() may
        // take none.
        $api->onReady(static function () use ($api, $legacy): void {
            foreach ($legacy->adoptions() as $key => $value) {
                $api->changeSetting($key, $value);
            }
        });

        // Blame and the gutter stripe: they own no widget and no region, so
        // they were extractable long before the rest and are unchanged.
        $this->activateBlameAndMarks($api, $settings, $gitPath);

        $git = new GitInfo($root, $settings, '' === $gitPath ? null : $gitPath);
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
        $commands = new GitCommands($git, $modals, $flash, $repaint, $trans, $refresher, $api->copyToClipboard(...), $events);
        $remote = new GitRemoteCommands(new GitRemote($root, '' === $gitPath ? null : $gitPath), $flash, $trans, $events, $modals, $repaint);

        $this->registerCommands($api, $commands, $remote);
        $api->registerMenu(
            title: 'Git',
            after: GitMenu::AFTER,
            items: GitMenu::items(),
            viewId: 'git',
            viewSafe: GitMenu::viewSafe(),
        );

        $branch = new GitBranchSegment($refresher, $settings, $commands, $trans);
        $api->registerStatusSegment($branch->statusCell(...));

        // The settings page writes the value; this is what makes it visible
        // without a restart. Two apply LIVE, and they are the two a user
        // toggles to see what happens: the branch cell and the gutter stripe.
        // The rest only have to reach the holder, because every reader of them
        // reads at the moment it acts — a commit reads signoff when it commits.
        // `path` is absent on purpose: it is baked into a process runner at
        // construction, so a setter would be a lie and the row says so.
        $api->onSettingChanged(static function (string $key, bool|int|string $value) use ($api, $settings, $repaint): void {
            // The holder first, always: every reader takes its value from here
            // at the moment it acts, so a commit picks up `signoff` on the next
            // commit without anything else happening.
            match ($key) {
                'showBranch' => $settings->setShowBranch((bool) $value),
                'gutterMarks' => $settings->setGutterMarks((bool) $value),
                'blameDefault' => $settings->setBlameDefault((bool) $value),
                'signCommits' => $settings->setSignCommits((bool) $value),
                'noVerify' => $settings->setNoVerify((bool) $value),
                'signoff' => $settings->setSignoff((bool) $value),
                'refreshInterval' => $settings->setRefreshInterval((int) $value),
                default => null, // path, and the two diff toggles the view writes itself
            };
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

        // The view is built LATER — its factory runs once, after plugins have
        // committed — but every listener has to be registered now, because the
        // buffer is drained at commit and a later `on()` is refused. So the
        // core-bus listeners go up front over a holder the factory fills, and
        // fire on nothing until it does.
        $slot = new ViewSlot();
        // Toggle, not open: Ctrl+Alt+D has always closed the view it opened.
        // One call, because "is it open?" is the registry's question — asking
        // it here would mean keeping the controller alive to read one bool,
        // and the controller does not exist yet.
        $api->adoptCommand('ide_git', static fn () => $api->toggleView('git'));
        foreach (GitWiring::VIEW_CORE as $event => $method) {
            $api->on($event, static function (PluginObservable $fact) use ($slot, $method): void {
                // @mago-expect analysis:string-member-selector -- the method name comes from GitWiring, which GitListenerContractTest pins to a real one
                $slot->view?->{$method}($fact);
            });
        }
        $api->registerView('git', fn (ViewServices $services): GitViewController => $slot->view = $this->buildView($api, $services, $git, $settings, $events, $flash, $commands, $remote));

        $api->onReady($refresher->refreshBranch(...));
        $api->onShutdown($git->cancelAll(...));
        // A save, a commit, a completed fetch or pull, or anything else that
        // moved the working tree: the branch indicator may be stale.
        $api->onFileSaved(static fn (string $path) => $refresher->refreshBranch());
        foreach (GitWiring::REFRESHER as $event => $method) {
            // @mago-expect analysis:less-specific-nested-argument-type -- GitListenerContractTest pins every method named here
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
    private function buildView(
        PluginApi $api,
        ViewServices $s,
        GitInfo $git,
        GitSettings $settings,
        EventDispatcher $events,
        Flash $flash,
        GitCommands $commands,
        GitRemoteCommands $remote,
    ): GitViewController {
        $panes = new GitPanes(
            new ChangesListWidget($s->translator, styles: $s->styles, events: $events),
            new DiffViewWidget($s->translator, $s->styles, $api->syntaxTheme(), $events),
            new CommitBoxWidget($s->translator, styles: $s->styles, cursorStyle: $s->cursorStyle),
            new HistoryListWidget($s->translator, styles: $s->styles, events: $events),
            new GitTabsWidget($s->translator, styles: $s->styles, events: $events),
        );
        // The ids and flex styles the container used to apply from #[AsWidget].
        // The ids are how the renderer and every rect-reading test name these
        // widgets; the flex values are what makes the diff pane take the slack
        // while the three stacked sidebar widgets keep their rendered height.
        $panes->changes->setId('git-changes')->setStyle(new Style(flex: 0));
        $panes->diffView->setId('git-diff')->setStyle(new Style(flex: 1));
        $panes->commitBox->setId('git-commit');
        $panes->history->setId('git-history');
        $panes->tabs->setId('git-tabs')->setStyle(new Style(flex: 0));

        $controller = new GitViewController(
            $git,
            $api->projectRoot(),
            $panes,
            $s->region,
            $s->host,
            new GitMouseRouter($s->hit, $s->host, $panes->changes, $panes->diffView, $panes->commitBox, $panes->history, $panes->tabs, $s->region),
            new GitContextMenus($s->hit, $s->host, $panes->changes, $panes->history, $commands, $s->translator),
            $flash,
            $s->translator,
            $events,
            new DiffPrefs($api->setting(...), $api->changeSetting(...)),
            $api->copyToClipboard(...),
            $remote,
            gitSettings: $settings,
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
            // @mago-expect analysis:less-specific-nested-argument-type -- the map's methods are pinned to real ones by GitListenerContractTest
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
        $api->registerSetting('refreshInterval', 'Status refresh interval (seconds)', 'int', 2);
        $api->registerSetting('path', 'Git executable path', 'text', '', hint: 'auto-detect');
        // The diff pane's two display toggles. Not on the page — the view sets
        // them from its own toolbar — but registered so they persist and so
        // reading one before it has ever been written yields a default.
        $api->registerSetting('diffSplit', 'Diff: side-by-side', 'bool', false);
        $api->registerSetting('diffHideWhitespace', 'Diff: hide whitespace changes', 'bool', false);
    }

    /** Blame annotations and the gutter stripe — the half that never needed the workbench. */
    private function activateBlameAndMarks(PluginApi $api, GitSettings $settings, string $gitPath): void
    {
        $path = '' === $gitPath ? null : $gitPath;
        // Built once, not per call: BlameAnnotations is a toggle, so it has to
        // remember whether it is on between the command and the hooks.
        $blame = new BlameClient($api->projectRoot(), $path);
        $annotations = new BlameAnnotations($blame, $api, $settings->blameDefault());

        $api->adoptCommand('git_blame', new BlameLine($blame, $api)->show(...));
        $api->adoptCommand('git_toggle_blame', $annotations->toggle(...));

        $api->onBufferSwitched(static fn (string $path) => $annotations->refreshIfEnabled());
        $api->onFileSaved(static fn (string $path) => $annotations->refreshIfEnabled());

        $git = new GitClient($api->projectRoot(), $path);
        $marks = new ChangeMarks($git, $api, $settings->gutterMarks());
        $stash = new Stash($git, $api);
        $index = new IndexCommands($git, $api);
        $api->adoptCommand('git_stage', $index->stageCurrent(...));
        $api->adoptCommand('git_undo_commit', $index->undoLastCommit(...));
        $api->adoptCommand('git_stash', $stash->push(...));
        $api->adoptCommand('git_stash_pop', $stash->pop(...));

        // From onReady, not here: PluginApi refuses every IDE action during
        // activate().
        $api->onReady(static fn () => $api->showChangeMarkGutter($settings->gutterMarks()));
        $api->onFileOpened($marks->refresh(...));
        $api->onFileSaved($marks->refresh(...));
        $api->onFileReloaded($marks->refresh(...));
        $api->onBufferSwitched($marks->refresh(...));
        // A commit, a stash, a pull: whatever moved, the marks are stale.
        // Not `$marks->refresh(...)`, which mago suggests and which would be a
        // TypeError: refresh() takes an optional ?string $path, so the event
        // object would land in it.
        $api->on(WorkingTreeChanged::class, static fn () => $marks->refresh());
        $api->on(GitCommitted::class, static fn () => $marks->refresh());
        $api->on(GitRemoteCompleted::class, static fn () => $marks->refresh());
    }
};
