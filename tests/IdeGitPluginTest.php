<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\Event\IdeShuttingDown;
use Porta\Event\TreeDropRequested;
use Porta\Ide;
use Porta\Plugin\Lifecycle\PluginLifecycle;
use Porta\Support\AsyncProcess;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\Widgets;
use Porta\Ui\ClickIntent;
use Porta\Ui\Glyphs;
use Porta\Widget\ContextMenu;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Blame through a real IDE — and above all, that moving it out of `src/` cost
 * the user nothing: both verbs keep core's bare ids through `adoptCommand`, so
 * the palette labels, the Git-menu entry and any `.porta.json` rebind of
 * `git_blame` all still land on this plugin's handler.
 */
final class IdeGitPluginTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    private static function repo(): TempProject
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n\$x = 1;\n",
            '.porta.json' => '{"enabledPlugins":["git"]}',
        ]);
        $project->initGit(name: 'tester', email: 't@t');
        $project->commitAll('init');

        return $project;
    }

    public function testTheBareCoreIdsAnswerAndKeepTheirGitMenuEntry(): void
    {
        $this->skipWithoutGit();
        $project = self::repo();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => function ($tui, $terminal, Ide $ide): void {
                    $labels = \array_column(Services::resolve($ide, CommandRegistry::class)->paletteCommands(), 'label');
                    // Core's labels, not "git:blame" — the ids were adopted bare.
                    $this->assertContains('Git: Blame Current Line', $labels);
                    $this->assertContains('Git: Toggle Blame Annotations', $labels);

                    // And the entry is still in the GIT menu, not the trailing
                    // Plugins one, because core still names the id.
                    $git = \array_values(\array_filter(
                        Probe::get($tui->getById('menu'), 'menus'),
                        static fn ($menu): bool => 'Git' === $menu->title,
                    ));
                    $this->assertCount(1, $git, 'the Git menu is built');
                    $this->assertContains('Git: Blame Current Line', \array_map(static fn ($item): string => $item->label, $git[0]->items));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAFactRaisedOnThePluginBusReachesCoreListeners(): void
    {
        // The bridge, from the side that would actually break. The view
        // dispatches GitCommitted on this plugin's OWN dispatcher — the code
        // raising it cannot tell a core fact from an internal one — and every
        // observer, including this plugin's own gutter, listens on core's.
        // Nothing connects them but the forwarder in plugin.php; drop it and
        // the marks stay stale after a commit, with no error anywhere.
        $this->skipWithoutGit();
        $project = self::repo();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => function ($tui, $terminal, Ide $ide): void {
                    $view = Services::resolve($ide, ViewRegistry::class)->find('git');
                    $this->assertInstanceOf(ContributedView::class, $view, 'the git view registered');

                    $seen = 0;
                    Services::resolve($ide, EventDispatcherInterface::class)
                        ->addListener(GitCommitted::class, static function () use (&$seen): void {
                            ++$seen;
                        });

                    // The plugin's own bus, reached the way the widgets reach it.
                    Probe::get($view, 'dispatcher')->dispatch(new GitCommitted());

                    $this->assertSame(1, $seen, 'a core fact raised on the plugin bus arrived on core\'s');

                    // And the other direction: a completed remote operation is
                    // a fact ANYTHING may raise — a future VCS plugin, a hook —
                    // so the view listens for it on core's bus rather than its
                    // own. It has to survive arriving before the view is open,
                    // which is the state here.
                    Services::resolve($ide, EventDispatcherInterface::class)->dispatch(new GitRemoteCompleted('fetch', true));
                    $this->assertFalse(Services::resolve($ide, ViewRegistry::class)->isOpen('git'), 'and a closed view took it without opening');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTheGutterStripeMarksModifiedAndAddedLines(): void
    {
        // The marks core used to compute. They arrive through
        // PluginApi::setChangeMarks now, so this only passes with the plugin
        // loaded — which is why it lives here rather than in core's git suite.
        $this->skipWithoutGit();
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit(name: 'tester', email: 't@t');
        $project->write('src/A.php', "<?php\n\$a = 1;\n\$b = 2;\n");
        $project->git('add', '-A');
        $project->git('commit', '-qm', 'init');
        // Line 2 modified, line 4 added, after the commit.
        $project->write('src/A.php', "<?php\n\$a = 999;\n\$b = 2;\n\$c = 3;\n");

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => function (Tui $tui, $terminal): void {
                    $editor = Widgets::editor($tui);
                    $joined = static fn (): string => \implode('', $editor->render(new RenderContext(80, 10)));

                    $this->await(static fn (): bool => \str_contains($joined(), "\x1b[38;5;75m┆"), what: 'the modified mark to land');
                    $this->assertStringContainsString("\x1b[38;5;114m▎", $joined(), 'and the added mark with it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The FILE tree's marks, which are a different question from the gutter's:
     * `git status` over the whole repository, so a changed file nobody has
     * opened is still marked. Only the plugin can answer it, which is why this
     * lives here.
     */
    public function testTheFileTreeMarksChangedFilesTheUserHasNotOpened(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit(name: 'tester', email: 't@t');
        $project->write('tracked.php', "<?php\n");
        $project->write('untouched.php', "<?php\n");
        $project->write('removed.php', "<?php\n");
        $project->git('add', '-A');
        $project->git('commit', '-qm', 'init');
        $project->write('tracked.php', "<?php\n// changed\n");
        $project->write('brandnew.php', "<?php\n");
        \unlink($project->path('removed.php')); // gone from disk: no tree row to mark

        $this->runIde(
            $project,
            $project->path('untouched.php'),
            [
                600 => function (Tui $tui): void {
                    $tree = Widgets::byId($tui, 'tree', FileTreeWidget::class);
                    $rows = static function () use ($tree): string {
                        $tree->beforeRender();

                        return AnsiUtils::stripAnsiCodes(\implode("\n", $tree->render(new RenderContext(30, 12))));
                    };

                    $this->await(static fn (): bool => 1 === \preg_match('/tracked\.php\s+┆$/m', $rows()), what: 'the modified file to be marked');
                    $this->assertMatchesRegularExpression('/brandnew\.php\s+▎$/m', $rows(), 'an untracked file reads as added');
                    $this->assertMatchesRegularExpression('/untouched\.php\s+$/m', $rows(), 'and an unchanged one carries nothing');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * A file MOVED in the tree gets its mark, without waiting for a save.
     *
     * The tree's marks are a whole-repository `git status`, refreshed only when
     * the working tree can have moved — a save, a commit, a stage, a pull. Core
     * moving a file was not on that list, so the moved file sat unmarked until
     * something unrelated happened to trigger a refresh. Drag-and-drop is what
     * made it easy to hit: moving a file is now a flick of the wrist.
     */
    public function testMovingAFileMarksItWithoutWaitingForASave(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit(name: 'tester', email: 't@t');
        $project->write('src/moved.php', "<?php\n");
        $project->write('anchor.php', "<?php\n");
        $project->git('add', '-A');
        $project->git('commit', '-qm', 'init');

        $this->runIde(
            $project,
            $project->path('anchor.php'),
            [
                600 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                    $tree = Widgets::byId($tui, 'tree', FileTreeWidget::class);
                    $rows = static function () use ($tree): string {
                        $tree->beforeRender();

                        return AnsiUtils::stripAnsiCodes(\implode("\n", $tree->render(new RenderContext(30, 12))));
                    };
                    // Everything is committed, so the control is a clean tree —
                    // otherwise a mark below could be one that was always there.
                    $this->await(
                        static fn (): bool => 1 === \preg_match('/anchor\.php\s+$/m', $rows()),
                        what: 'the first status to come back clean',
                    );

                    Services::resolve($ide, EventDispatcherInterface::class)->dispatch(
                        new TreeDropRequested([$project->path('src/moved.php')], $project->dir, copy: false),
                    );

                    $this->assertFileExists($project->path('moved.php'), 'the move itself happened');
                    $this->await(
                        static fn (): bool => 1 === \preg_match('/moved\.php\s+'.Glyphs::VCS_ADDED.'$/m', $rows()),
                        what: 'the moved file to be marked',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testADiskReloadRefreshesTheStripe(): void
    {
        // The poller replaced a clean buffer; the marks must follow. Core
        // dispatches BufferReloaded and the plugin listens through
        // onFileReloaded, which is the hook this handover needed.
        $this->skipWithoutGit();
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}', 'a.txt' => "one\ntwo\n"]);
        $project->initGit();
        $project->commitAll('init');

        $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                400 => function (Tui $tui, $terminal, $ide) use ($project): void {
                    $decorations = Widgets::editor($tui)->decorations;
                    $this->assertSame([], $decorations->vcsMarks, 'committed and unmodified: no marks');

                    $project->write('a.txt', "one\nCHANGED\n"); // external edit
                    $ide->pollFilesystem();

                    $this->await(static fn (): bool => [] !== $decorations->vcsMarks, what: 'the reload to recompute the stripe');
                    $this->assertArrayHasKey(1, $decorations->vcsMarks, 'the changed line is marked');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testBlamingTheCaretLineFlashesTheCommit(): void
    {
        $this->skipWithoutGit();
        $project = self::repo();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => function ($tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('git_blame');
                    $this->await(
                        static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'tester'),
                        what: 'the blame flash to name the author',
                    );
                    $this->assertStringContainsString('init', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'and the commit subject');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTogglingAnnotationsPaintsThemIntoTheEditor(): void
    {
        $this->skipWithoutGit();
        $project = self::repo();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    $editorScreen = static fn (): string => AnsiUtils::stripAnsiCodes(\implode("\n", Widgets::editor($tui)->render(new RenderContext(120, 20))));
                    Services::resolve($ide, CommandRegistry::class)->run('git_toggle_blame');
                    $this->await(static fn (): bool => \str_contains($editorScreen(), 'tester'), what: 'the annotations to paint');

                    Services::resolve($ide, CommandRegistry::class)->run('git_toggle_blame');
                    $this->assertStringNotContainsString('tester', $editorScreen(), 'toggling off clears them');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testAProjectConfiguredBeforeTheMoveKeepsItsChoicesAndIsRewritten(): void
    {
        // The upgrade path, end to end: a `.porta.json` written when git was
        // core, opened for the first time after it became a plugin. The value
        // has to survive AND the file has to be rewritten, or it keeps
        // answering the same question in two places and the settings page shows
        // the default while the feature behaves otherwise.
        $this->skipWithoutGit();
        $project = new TempProject([
            'src/A.php' => "<?php\n",
            '.porta.json' => \json_encode(
                [
                    'enabledPlugins' => ['git'],
                    // Where the pre-move writer actually put them: inside the
                    // `settings` block, never at the top level.
                    'settings' => [
                        'gitShowBranch' => false,
                        'gitRefreshInterval' => 7,
                    ],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        ]);
        $project->initGit(name: 'tester', email: 't@t');
        $project->commitAll('init');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => function (): void {
                    $this->addToAssertionCount(1);
                },
            ],
        );

        $porta = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($porta);
        $this->assertFalse($porta['plugins']['git']['showBranch'], 'the pre-move choice was adopted');
        $this->assertSame(7, $porta['plugins']['git']['refreshInterval']);
        // The old keys are left where they are: this file is hand-editable and
        // core skips them without warning (IdeConfig::LEGACY_SETTINGS), so
        // rewriting a user\'s file further than needed would be the surprise.
        $this->assertFalse($porta['settings']['gitShowBranch'], 'and the old key is not silently deleted');
    }

    public function testTogglingTheGutterSettingAppliesWithoutARestart(): void
    {
        // These two were LIVE toggles while git was core, applied by
        // SettingsGitController. A plugin reads its settings once at
        // activate(), so without `PluginApi::onSettingChanged()` they would
        // both have become restart-required — and the failure is the worst
        // kind: the row flips, the file is written, and nothing happens.
        $this->skipWithoutGit();
        $project = self::repo();
        $project->write('src/A.php', "<?php\n\$a = 999;\n");

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => function (Tui $tui, $terminal, Ide $ide): void {
                    $editor = Widgets::editor($tui);
                    $joined = static fn (): string => \implode('', $editor->render(new RenderContext(80, 10)));
                    $this->await(static fn (): bool => \str_contains($joined(), "\x1b[38;5;75m┆"), what: 'the modified mark to land');

                    // The seam the Settings page reaches once it has written
                    // the value. The write itself is `SettingsPluginsController`'s
                    // and is pinned in its own test; what is unproven without
                    // this is that the plugin ACTS on being told.
                    Services::resolve($ide, PluginLifecycle::class)->onPluginSettingChanged('git', 'gutterMarks', false);
                    $this->frame($tui);

                    $this->assertStringNotContainsString("\x1b[38;5;75m┆", $joined(), 'the strip went, and took the marks with it');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testEveryOtherSettingReachesTheHolderTheReadersShare(): void
    {
        // The five that apply on the NEXT action rather than on screen — a
        // commit reads `signoff` when it commits, the poll reads the interval
        // when it next fires. Nothing about them is visible, which is exactly
        // why a test has to say so: an arm dropped from the match here looks
        // identical to one that works until someone tries to sign a commit.
        //
        // `path` is the control, and the assertion has to be that it changes
        // NOTHING — not that `path()` is unchanged, which is a tautology since
        // it has no setter. The binary is baked into a process runner at
        // construction; the match's default arm exists to say so.
        $this->skipWithoutGit();
        $project = self::repo();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => function ($tui, $terminal, Ide $ide): void {
                    $view = Services::resolve($ide, ViewRegistry::class)->find('git');
                    $this->assertInstanceOf(ContributedView::class, $view);
                    $settings = Probe::get($view, 'gitSettings');
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);

                    foreach ([
                        'blameDefault' => true,
                        'signCommits' => true,
                        'noVerify' => true,
                        'signoff' => true,
                        'refreshInterval' => 9,
                        'path' => '/opt/git',
                    ] as $key => $value) {
                        $lifecycle->onPluginSettingChanged('git', $key, $value);
                    }

                    $this->assertTrue($settings->blameDefault);
                    $this->assertTrue($settings->signCommits);
                    $this->assertTrue($settings->noVerify);
                    $this->assertTrue($settings->signoff);
                    $this->assertSame(9, $settings->refreshInterval);
                    // `path` reaches the holder like the rest now; the runners
                    // read it through a closure per spawn, so it is live too.
                    $this->assertSame('/opt/git', $settings->path);

                    // The control, and it has to start from the value an
                    // unhandled key would OVERWRITE: every holder is truthy by
                    // default, so a default arm that wrongly applied
                    // `diffSplit => true` would be invisible against them.
                    $lifecycle->onPluginSettingChanged('git', 'showBranch', false);
                    $lifecycle->onPluginSettingChanged('git', 'gutterMarks', false);
                    $before = [
                        $settings->showBranch,
                        $settings->gutterMarks,
                        $settings->blameDefault,
                        $settings->signCommits,
                        $settings->noVerify,
                        $settings->signoff,
                        $settings->refreshInterval,
                        $settings->path,
                    ];
                    // The two diff toggles the VIEW writes itself: the match
                    // must not name them, and must not fall through to a holder.
                    $lifecycle->onPluginSettingChanged('git', 'diffSplit', true);
                    $lifecycle->onPluginSettingChanged('git', 'diffHideWhitespace', true);
                    $after = [
                        $settings->showBranch,
                        $settings->gutterMarks,
                        $settings->blameDefault,
                        $settings->signCommits,
                        $settings->noVerify,
                        $settings->signoff,
                        $settings->refreshInterval,
                        $settings->path,
                    ];

                    $this->assertSame([false, false], [$before[0], $before[1]], 'the two live toggles are off before the control');
                    $this->assertSame($before, $after, 'a key the match does not name changes nothing');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTheGitPathSettingAppliesWithoutARestart(): void
    {
        // `path` used to be baked into a process runner at construction — the
        // holder said so, and the setting had no setter at all. The runners
        // resolve it per spawn now, so pointing it at nothing breaks the next
        // git command and restoring it fixes the one after, with no restart.
        $this->skipWithoutGit();
        $project = self::repo();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => function ($tui, $terminal, Ide $ide): void {
                    $view = Services::resolve($ide, ViewRegistry::class)->find('git');
                    $this->assertInstanceOf(ContributedView::class, $view);
                    $git = Probe::get($view, 'git');
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                    $discovered = $git->binary();
                    $this->assertNotNull($discovered, 'a real repo with git on PATH');

                    $lifecycle->onPluginSettingChanged('git', 'path', '/custom/bin/git');
                    $this->assertSame('/custom/bin/git', $git->binary(), 'the edited path is what the next spawn would use');

                    $lifecycle->onPluginSettingChanged('git', 'path', '');
                    $this->assertSame($discovered, $git->binary(), 'and clearing it goes back to discovery');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTogglingTheBranchSettingHidesTheCellWithoutARestart(): void
    {
        $this->skipWithoutGit();
        $project = self::repo();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => function (Tui $tui, $terminal, Ide $ide): void {
                    // Read off the TERMINAL, not the widget: the cell is pulled
                    // by the status bar's provider during a real frame, and a
                    // bare render() of the widget re-uses its cached line.
                    $shown = static fn (): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->await(static fn (): bool => \str_contains($shown(), Glyphs::STATUS_BRANCH), what: 'the branch to resolve');

                    Services::resolve($ide, PluginLifecycle::class)->onPluginSettingChanged('git', 'showBranch', false);
                    $terminal->clearOutput();
                    $this->frame($tui);

                    $this->assertStringNotContainsString(Glyphs::STATUS_BRANCH, $shown(), 'the cell is gone on the next frame');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The branch cell wears a select's `▾`, so it opens like one: on a LEFT
     * click, where only a right-click used to reach its menu.
     */
    public function testALeftClickOnTheBranchCellOpensItsMenu(): void
    {
        $this->skipWithoutGit();
        $project = self::repo();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $shown = static fn (): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->await(static fn (): bool => \str_contains($shown(), Glyphs::STATUS_BRANCH), what: 'the branch to resolve');
                    $grid = $this->screenGrid($tui, $terminal);
                    $row = \count($grid) - 1; // the status bar is the last row
                    $col = \mb_strpos($grid[$row], Glyphs::STATUS_BRANCH);
                    $this->assertNotFalse($col, 'the branch cell is on the status row');
                    $this->assertStringContainsString(Glyphs::STATUS_MENU, $grid[$row], 'and says it opens a menu');

                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col + 1, $row + 1));

                    $this->assertTrue(Services::resolve($ide, ContextMenu::class)->isOpen(), 'a left click opened the branch menu');
                },
            ],
        );
    }

    public function testShutdownStopsBothLanesAndTheHalvesShareTheGeneralOne(): void
    {
        // Two wiring facts a mutation sweep found nothing asserting, and both
        // are what this plugin's process group exists for.
        //
        // Sleepers rather than real git: the subject is the WIRING — that the
        // shutdown hook reaches the group and the group reaches both lanes —
        // and a real git call would make its timing the subject instead.
        $this->skipWithoutGit();
        $project = self::repo();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => function ($tui, $terminal, Ide $ide) use ($project): void {
                    $this->awaitGit($ide); // the boot's own status/branch reads start the count
                    $view = Services::resolve($ide, ViewRegistry::class)->find('git');
                    $this->assertInstanceOf(ContributedView::class, $view);
                    $general = Probe::get(Probe::get($view, 'git'), 'runner');
                    $general = Probe::get($general, 'lane');
                    $remote = Probe::get(Probe::get(Probe::get($view, 'remoteCommands'), 'remote'), 'lane');

                    // The editor half runs in the SAME lane as the view's git,
                    // asserted by OBSERVING it: run blame — an editor-half verb
                    // reached through a client the view cannot see — and watch
                    // the view's lane account for it. Comparing the two lanes by
                    // identity is not available here and would not be worth much
                    // if it were; this is the property the completion barrier
                    // actually depends on.
                    $this->assertSame(0, $general->inFlight(), 'idle before blame');
                    Services::resolve($ide, CommandRegistry::class)->run('git_blame');
                    $this->assertSame(1, $general->inFlight(), 'the editor half landed in the view\'s lane');
                    $this->await(static fn (): bool => 0 === $general->inFlight(), what: 'blame to finish');

                    $sleep = static fn (): AsyncProcess => new AsyncProcess(['sleep', '30'], \sys_get_temp_dir());
                    $general->start($sleep(), static function (): void {});
                    $remote->start($sleep(), static function (): void {});
                    $this->assertSame(1, $general->inFlight());
                    $this->assertSame(1, $remote->inFlight());

                    Services::resolve($ide, EventDispatcherInterface::class)->dispatch(new IdeShuttingDown($project->dir));

                    $this->assertSame(0, $general->inFlight(), 'shutdown stopped the general lane');
                    $this->assertSame(0, $remote->inFlight(), 'and the remote one, which it used not to');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testTheGitViewTogglesLiveWithItsMenuAndBranchCell(): void
    {
        // The real consumer of the live view lifecycle: enable git mid-session
        // in a repo (Git menu + view arrive), disable while the VIEW IS OPEN
        // (editor restored, menu and view gone), re-enable (fresh view works).
        $this->skipWithoutGit();
        $project = new TempProject([
            'src/A.php' => "<?php\n\$x = 1;\n",
            '.porta.json' => '{"enabledPlugins":[]}', // git OFF at boot
        ]);
        $project->initGit(name: 'tester', email: 't@t');
        $project->commitAll('init');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    $views = Services::resolve($ide, ViewRegistry::class);
                    $lifecycle = Services::resolve($ide, PluginLifecycle::class);
                    $menuTitles = static fn (): array => \array_column(Probe::get($tui->getById('menu'), 'menus'), 'title');

                    $this->frame($tui);
                    $this->assertNull($views->find('git'), 'off at boot');
                    $this->assertNotContains('Git', $menuTitles());

                    $lifecycle->enable('git');
                    $this->frame($tui);
                    $this->assertNotNull($views->find('git'), 'a first-time enable builds the git view');
                    $this->assertContains('Git', $menuTitles(), 'and its menu');

                    Services::resolve($ide, CommandRegistry::class)->run('ide_git');
                    $this->frame($tui);
                    $this->assertTrue($views->isOpen('git'), 'the adopted toggle opens the fresh view');

                    $lifecycle->disable('git');
                    $this->frame($tui);
                    $this->assertNull($views->find('git'), 'disabling while open retracts the view');
                    $this->assertNotContains('Git', $menuTitles());
                    $this->assertSame('editor', $tui->getFocus()?->getId(), 'the editor got the workspace back');

                    $lifecycle->enable('git');
                    $this->frame($tui);
                    Services::resolve($ide, CommandRegistry::class)->run('ide_git');
                    $this->frame($tui);
                    $this->assertTrue($views->isOpen('git'), 're-enable round-trips to a working view');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testALiveDisableStopsBothLanesTheShutdownHookNoLongerCovers(): void
    {
        // The disable path strips the plugin's listeners — the onShutdown hook
        // among them — so cancellation must arrive through DisposablePlugin
        // instead. Sleepers again: the subject is that the teardown reaches
        // the lanes, not git's timing.
        $this->skipWithoutGit();
        $project = self::repo();

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => function ($tui, $terminal, Ide $ide): void {
                    $this->awaitGit($ide); // the boot's own status/branch reads start the count
                    $view = Services::resolve($ide, ViewRegistry::class)->find('git');
                    $this->assertInstanceOf(ContributedView::class, $view);
                    $general = Probe::get(Probe::get(Probe::get($view, 'git'), 'runner'), 'lane');
                    $remote = Probe::get(Probe::get(Probe::get($view, 'remoteCommands'), 'remote'), 'lane');

                    $sleep = static fn (): AsyncProcess => new AsyncProcess(['sleep', '30'], \sys_get_temp_dir());
                    $general->start($sleep(), static function (): void {});
                    $remote->start($sleep(), static function (): void {});
                    $this->assertSame(1, $general->inFlight());
                    $this->assertSame(1, $remote->inFlight());

                    Services::resolve($ide, PluginLifecycle::class)->disable('git');

                    $this->assertSame(0, $general->inFlight(), 'the live disable stopped the general lane');
                    $this->assertSame(0, $remote->inFlight(), 'and the remote one');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testComparingTwoFilesPickedInTheTreeLandsInAPanel(): void
    {
        // The whole path, because every piece of it is a join: the tree's
        // selection, the plugin API that exposes it, an adopted core action,
        // git's own --no-index, and a dock tab. Each half passes its own tests
        // with the feature still not working.
        $this->skipWithoutGit();
        $project = new TempProject([
            'a.php' => "<?php\n\$x = 1;\n",
            'b.php' => "<?php\n\$x = 2;\n",
            '.porta.json' => '{"enabledPlugins":["git"]}',
        ]);
        $project->initGit(name: 'tester', email: 't@t');
        $project->commitAll('init');

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                300 => function (Tui $tui, $terminal, Ide $ide): void {
                    $tree = Widgets::byId($tui, 'tree', FileTreeWidget::class);
                    // Rows are alphabetical with dot files shown, so the tree
                    // reads `.porta.json`, `a.php`, `b.php` — and the cursor
                    // starts on the first of them.
                    $tree->clickRow(2);
                    $tree->clickRow(3, ClickIntent::Add);
                    $this->assertSame(
                        ['a.php', 'b.php'],
                        \array_map(basename(...), $tree->selectedPaths()),
                        'the pair is the one the panel is about to compare',
                    );

                    Services::resolve($ide, CommandRegistry::class)->run('git_compare_files');
                    // `$x = 2;` is b.php's line and b.php is not open, so only
                    // the comparison can put it on screen.
                    // `$x = 2;` is b.php's line and b.php is not open, so only
                    // the comparison can put it on screen.
                    $this->await(
                        static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '$x = 2;'),
                        what: 'the comparison to reach the panel',
                    );
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('-$x = 1;', $screen, "the left file's line, marked as taken away");
                    $this->assertStringContainsString('+$x = 2;', $screen, "and the right file's, marked as brought in");
                    $this->assertStringContainsString('a.php ↔ b.php', $screen, 'and the pair is named, because the rows do not say which file is which');
                },
                600 => function (Tui $tui, $terminal, Ide $ide): void {
                    // And the way a person finds it: the tree row's own
                    // right-click, on the pair that is still selected.
                    $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('tree'));
                    $terminal->clearOutput();
                    $this->frame($tui);
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 3, $rect->row + 3));
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'the tree row menu opened');
                    $this->frame($tui);
                    $this->assertStringContainsString(
                        'Compare Selected Files',
                        AnsiUtils::stripAnsiCodes($terminal->getOutput()),
                        'the command is on the surface where the pair was built',
                    );
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The gutter marks move with an UNSAVED edit, through the whole stack.
     *
     * The unit cases pin the arithmetic and the derivation; this is the join,
     * and the only place the two halves meet the way a user does: the PLUGIN
     * computes the marks from git and publishes them, CORE moves them as the
     * document changes underneath. Nothing here saves, so the only thing that
     * can move a mark is the remap — no `git diff` has run since the marks
     * landed.
     *
     * Read off the GUTTER specifically, not the whole row. The marker stripe in
     * the right margin paints the same glyphs for the same lines, so a
     * `str_contains` over the line matches both and reports rows that are the
     * stripe's rather than the gutter's — which is what the first draft of this
     * test did.
     */
    public function testTheGutterMarksMoveWithAnUnsavedEdit(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}']);
        $project->initGit(name: 'tester', email: 't@t');
        $project->write('src/A.php', "<?php\n\$a = 1;\n\$b = 2;\n");
        $project->git('add', '-A');
        $project->git('commit', '-qm', 'init');
        $project->write('src/A.php', "<?php\n\$a = 999;\n\$b = 2;\n"); // line 1 modified

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                400 => function (Tui $tui, $terminal): void {
                    $editor = Widgets::editor($tui);
                    /** @return list<string> e.g. ['M1'] — the kind and row of each GUTTER mark */
                    $gutterMarks = static function () use ($editor): array {
                        $out = [];
                        foreach ($editor->render(new RenderContext(80, 10)) as $row => $line) {
                            $gutter = AnsiUtils::sliceByColumn($line, 0, $editor->gutterWidth());
                            if (\str_contains($gutter, Glyphs::VCS_MODIFIED)) {
                                $out[] = 'M'.$row;
                            }
                            if (\str_contains($gutter, Glyphs::VCS_ADDED)) {
                                $out[] = 'A'.$row;
                            }
                        }

                        return $out;
                    };

                    $this->await(static fn (): bool => [] !== $gutterMarks(), what: 'the modified mark to land');
                    $this->assertSame(['M1'], $gutterMarks(), 'git says line 1 differs from HEAD');

                    // Split line 0, pushing everything below it down one. Nothing
                    // saves, so nothing recomputes.
                    $tui->setFocus($editor);
                    $editor->getDocument()->setCursor(0, 0);
                    $tui->handleInput("\r");

                    $this->assertSame(
                        ['M0', 'A1', 'M2'],
                        $gutterMarks(),
                        "git's mark followed its line to row 2; the split line is modified and the line it made is added",
                    );
                    $this->assertTrue($editor->getDocument()->isDirty(), 'and all of that on an UNSAVED buffer');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
