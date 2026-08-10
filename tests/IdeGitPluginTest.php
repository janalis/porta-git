<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;

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
                300 => function ($tui, $terminal, $ide): void {
                    $labels = array_column(Services::resolve($ide, CommandRegistry::class)->paletteCommands(), 'label');
                    // Core's labels, not "git:blame" — the ids were adopted bare.
                    $this->assertContains('Git: Blame Current Line', $labels);
                    $this->assertContains('Git: Toggle Blame Annotations', $labels);

                    // And the entry is still in the GIT menu, not the trailing
                    // Plugins one, because core still names the id.
                    $git = array_values(array_filter(
                        \Porta\Tests\Support\Probe::get($tui->getById('menu'), 'menus'),
                        static fn ($menu): bool => 'Git' === $menu->title,
                    ));
                    $this->assertCount(1, $git, 'the Git menu is built');
                    $this->assertContains('Git: Blame Current Line', array_map(static fn ($item): string => $item->label, $git[0]->items));
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
                400 => function ($tui, $terminal, $ide): void {
                    $view = Services::resolve($ide, ViewRegistry::class)->find('git');
                    self::assertNotNull($view, 'the git view registered');

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
                400 => function ($tui, $terminal): void {
                    $editor = $tui->getById('editor');
                    $joined = static fn (): string => implode('', $editor->render(new \Symfony\Component\Tui\Render\RenderContext(80, 10)));

                    $this->await(static fn (): bool => str_contains($joined(), "\x1b[38;5;75m┆"), what: 'the modified mark to land');
                    $this->assertStringContainsString("\x1b[38;5;114m▎", $joined(), 'and the added mark with it');
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
                400 => function ($tui, $terminal, $ide) use ($project): void {
                    $decorations = Probe::get($tui->getById('editor'), 'decorations');
                    $this->assertSame([], $decorations->vcsMarks(), 'committed and unmodified: no marks');

                    $project->write('a.txt', "one\nCHANGED\n"); // external edit
                    $ide->pollFilesystem();

                    $this->await(static fn (): bool => [] !== $decorations->vcsMarks(), what: 'the reload to recompute the stripe');
                    $this->assertArrayHasKey(1, $decorations->vcsMarks(), 'the changed line is marked');
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
                300 => function ($tui, $terminal, $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('git_blame');
                    $this->await(
                        static fn (): bool => str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'tester'),
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
                300 => function ($tui, $terminal, $ide): void {
                    $editorScreen = static fn (): string => AnsiUtils::stripAnsiCodes(implode("\n", $tui->getById('editor')->render(new \Symfony\Component\Tui\Render\RenderContext(120, 20))));
                    Services::resolve($ide, CommandRegistry::class)->run('git_toggle_blame');
                    $this->await(static fn (): bool => str_contains($editorScreen(), 'tester'), what: 'the annotations to paint');

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
            '.porta.json' => json_encode([
                'enabledPlugins' => ['git'],
                'settings' => [],
                'gitShowBranch' => false,
                'gitRefreshInterval' => 7,
            ], JSON_THROW_ON_ERROR),
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

        $porta = json_decode((string) file_get_contents($project->path('.porta.json')), associative: true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($porta);
        $this->assertFalse($porta['plugins']['git']['showBranch'], 'the pre-move choice was adopted');
        $this->assertSame(7, $porta['plugins']['git']['refreshInterval']);
        // The old keys are left where they are: this file is hand-editable and
        // core skips them without warning (IdeConfig::LEGACY_SETTINGS), so
        // rewriting a user\'s file further than needed would be the surprise.
        $this->assertFalse($porta['gitShowBranch'], 'and the old key is not silently deleted');
    }
}
