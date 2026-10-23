<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\View\GitContextMenus;
use Porta\GitPlugin\View\ToolbarCell;
use Porta\GitPlugin\Widget\GitToolbarWidget;
use Porta\Ide;
use Porta\Layout\PaneSplit;
use Porta\Layout\ViewRegions;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\ViewWindowCells;
use Porta\Tests\Support\Widgets;
use Porta\Widget\ContextMenu;
use Porta\Widget\SeparatorWidget;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\LineBufferInterface;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The git view's toolbar, in the running IDE: it says which repository and
 * branch, how far the branch is from its upstream, and its first two cells
 * drop their menus where they were drawn.
 */
final class GitToolbarE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;
    use UsesBundledPlugins;
    use ViewWindowCells;

    /** @return list<string> the open context menu's labels */
    private static function menuLabels(Ide $ide): array
    {
        $menu = Services::resolve($ide, ContextMenu::class);

        return $menu->isOpen() ? \array_map(static fn (array $row): string => \trim(AnsiUtils::stripAnsiCodes($row['segment'])), $menu->segments($menu->panelWidth())) : [];
    }

    public function testTheToolbarSaysWhereTheBranchIsAndDropsItsMenus(): void
    {
        $this->skipWithoutGit();
        \putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['a.txt' => "one\n", '.porta.json' => '{"enabledPlugins":["diff","git"]}']);
        $project->initGit('main');
        $project->commitAll('first');
        // An upstream one commit behind: the remote button offers a push.
        $project->git('branch', 'base');
        $project->git('branch', '--set-upstream-to=base');
        $project->write('a.txt', "two\n");
        $project->commitAll('second');

        $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    // Before the view is drawn there is nothing to hang a menu from.
                    $menus = Services::resolve($ide, ViewRegistry::class)->find('git')?->contextMenus();
                    $this->assertInstanceOf(GitContextMenus::class, $menus);
                    $menus->openToolbarMenu(ToolbarCell::Branch);
                    $this->assertSame([], self::menuLabels($ide), 'not drawn: no menu');
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    // What the toolbar PAINTED: an ordinary frame, then its
                    // render cache. Not render() — that skips the cache, and a
                    // toolbar that kept its first, empty paint passed — and
                    // not a forced frame, which re-renders everything.
                    $toolbar = Widgets::byId($tui, 'git-toolbar', GitToolbarWidget::class);
                    $text = static function () use ($tui, $toolbar): string {
                        $tui->requestRender();
                        $tui->processRender();
                        $painted = Probe::get($toolbar, 'renderCacheLines');

                        return $painted instanceof LineBufferInterface ? AnsiUtils::stripAnsiCodes(\implode("\n", $painted->toArray())) : '';
                    };
                    // The three answers land in any order; await the last of them.
                    $this->await(static fn (): bool => \str_contains($text(), 'Push ⇡1') && \str_contains($text(), '⑂ main'), what: 'the branch and its upstream');
                    $this->assertStringContainsString('⑂ main', $text(), 'the branch');
                    $this->assertStringContainsString('⌂ '.\basename($project->dir), $text(), 'the repository, by its folder');
                    $this->frame($tui);
                    // The repository cell stands over the sidebar: its divider is the sidebar's.
                    $renderer = Services::resolve($ide, Renderer::class);
                    $repository = $toolbar->span(ToolbarCell::Repository);
                    $this->assertNotNull($repository);
                    $this->assertSame(
                        Widgets::rect($renderer, Widgets::byId($tui, 'git-separator', SeparatorWidget::class))->col,
                        Widgets::rect($renderer, $toolbar)->col + $repository[1],
                    );

                    // Over "main", in the branch cell.
                    $branch = $toolbar->span(ToolbarCell::Branch);
                    $this->assertNotNull($branch);
                    $tui->handleInput(self::widgetSgr($ide, 'git-toolbar', 0, 0, $branch[0] + 3));
                    $tui->handleInput(self::widgetSgr($ide, 'git-toolbar', 0, 0, $branch[0] + 3, release: true));
                    $this->frame($tui);
                    $this->assertSame(['Switch Branch…', 'New Branch…', 'Delete Branch…'], self::menuLabels($ide), 'the branch cell drops the branch verbs');

                    $tui->handleInput("\x1b");
                    $this->frame($tui);
                    $tui->handleInput(self::widgetSgr($ide, 'git-toolbar', 0, 0, 2));
                    $tui->handleInput(self::widgetSgr($ide, 'git-toolbar', 0, 0, 2, release: true));
                    $this->frame($tui);
                    $this->assertSame(['Worktrees…', 'New Worktree…'], self::menuLabels($ide), 'the repository cell drops the worktree verbs');
                    $tui->handleInput("\x1b");
                    $this->frame($tui);

                    // The remote cell runs its operation: it has no menu to drop.
                    $menus = Services::resolve($ide, ViewRegistry::class)->find('git')?->contextMenus();
                    $this->assertInstanceOf(GitContextMenus::class, $menus);
                    $menus->openToolbarMenu(ToolbarCell::Remote);
                    $this->assertSame([], self::menuLabels($ide));
                },
            ],
        );
    }

    /**
     * GitHub Desktop's alignment, on a roomy screen in History: the toolbar's
     * `┴` stand on the two dividers below it, the files' heading is level
     * with the diff's file header, and a drag of the divider between them
     * moves the toolbar's cell with it — and is kept.
     */
    public function testHistorysColumnsLineUpUnderTheToolbarAndItsDividerDrags(): void
    {
        $this->skipWithoutGit();
        \putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['src/a.txt' => "one\n", '.porta.json' => '{"enabledPlugins":["diff","git"]}']);
        $project->initGit('main');
        $project->commitAll('first');
        $project->write('src/a.txt', "one\ntwo\n");
        $project->commitAll('second');

        $this->runIde(
            $project,
            $project->path('src/a.txt'),
            [
                100 => static function (Tui $tui): void {
                    $tui->handleInput("\x1b\x04"); // open the git view
                },
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $tui->handleInput('2'); // History
                    $this->awaitPainted($tui, $terminal, '1 changed file');
                    $this->awaitPainted($tui, $terminal, '+two'); // the first file's patch, beside it
                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = static fn (string $id): WidgetRect => Widgets::rect($renderer, Widgets::byId($tui, $id, AbstractWidget::class));
                    $dividers = static fn (): array => [$rect('git-separator')->col, $rect('git-columns-separator')->col];
                    $joins = function () use ($tui, $terminal, $rect): array {
                        $row = $this->screenGrid($tui, $terminal)[$rect('git-toolbar')->row + 2];

                        return \array_keys(\mb_str_split($row), '┴', strict: true);
                    };

                    $this->assertSame($dividers(), $joins(), 'each toolbar divider stands on a pane divider');
                    $this->assertSame($rect('git-commit-details')->row, $rect('git-diff')->row, 'the files and the diff start on one row');
                    $heading = $this->screenGrid($tui, $terminal)[$rect('git-diff')->row];
                    $this->assertStringContainsString('1 changed file', $heading);
                    $this->assertStringContainsString('src/a.txt', $heading, 'level with the file header');
                    $this->assertStringContainsString('⚙ ▾', $heading);

                    // Six columns right, by the divider between the files and the diff.
                    $from = $rect('git-columns-separator')->col;
                    $tui->handleInput(self::widgetSgr($ide, 'git-columns-separator', 0, 3, 0));
                    $tui->handleInput(self::widgetSgr($ide, 'git-columns-separator', 32, 3, 6));
                    $tui->handleInput(self::widgetSgr($ide, 'git-columns-separator', 0, 3, 6, release: true));
                    $this->frame($tui);
                    $this->assertGreaterThan($from, $rect('git-columns-separator')->col, 'the divider moved, as far as the diff\'s minimum lets it');
                    $this->assertSame($dividers(), $joins(), 'and the toolbar\'s cell with it');
                    $layout = (string) \file_get_contents($project->path('.porta/layout.json'));
                    $this->assertStringContainsString('"git:columns"', $layout, 'kept for the next session');

                    // Narrowed until the columns stack: the divider is gone,
                    // and no join is drawn where it last stood.
                    $terminal->simulateResize(96, 40);
                    $split = Services::resolve($ide, ViewRegions::class)->find('git')?->split('columns');
                    $this->assertInstanceOf(PaneSplit::class, $split);
                    $this->await(function () use ($tui, $split): bool {
                        $this->frame($tui);

                        return $split->isNarrow();
                    }, what: 'the columns to stack');
                    $this->frame($tui);
                    $header = $rect('git-commit-header');
                    $rule = $this->screenGrid($tui, $terminal)[$header->row + $header->rows - 1];
                    $this->assertStringNotContainsString('┬', $rule, 'no join for a divider that is not drawn');
                    $this->assertStringContainsString('changed file', $rule, 'the rule counts the files instead');
                },
            ],
            columns: 140,
            rows: 40,
        );
    }
}
