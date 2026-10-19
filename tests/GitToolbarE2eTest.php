<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\View\GitContextMenus;
use Porta\GitPlugin\View\ToolbarCell;
use Porta\GitPlugin\Widget\GitToolbarWidget;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\ViewWindowCells;
use Porta\Tests\Support\Widgets;
use Porta\Widget\ContextMenu;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

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
        $project = new TempProject(['a.txt' => "one\n", '.porta.json' => '{"enabledPlugins":["git"]}']);
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
                    // Read off the toolbar's own rows: a differential paint
                    // need not write a cell run in one piece.
                    $toolbar = Widgets::byId($tui, 'git-toolbar', GitToolbarWidget::class);
                    $width = Widgets::rect(Services::resolve($ide, Renderer::class), $toolbar)->columns;
                    $text = static fn (): string => AnsiUtils::stripAnsiCodes(\implode("\n", $toolbar->render(new RenderContext($width, 3))));
                    // The three answers land in any order; await the last of them.
                    $this->await(static fn (): bool => \str_contains($text(), 'Push ⇡1') && \str_contains($text(), '⑂ main'), what: 'the branch and its upstream');
                    $this->assertStringContainsString('⑂ main', $text(), 'the branch');
                    $this->assertStringContainsString('⌂ '.\basename($project->dir), $text(), 'the repository, by its folder');
                    $this->frame($tui);

                    // Over "main", in the branch cell.
                    $tui->handleInput(self::widgetSgr($ide, 'git-toolbar', 0, 0, \mb_strlen(' ⌂ '.\basename($project->dir).' ▾ │ ⑂ ')));
                    $tui->handleInput(self::widgetSgr($ide, 'git-toolbar', 0, 0, \mb_strlen(' ⌂ '.\basename($project->dir).' ▾ │ ⑂ '), release: true));
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

        $this->addToAssertionCount(1);
    }
}
