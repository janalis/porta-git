<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\View;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\Widgets;
use Porta\Widget\ContextMenu;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;

/**
 * The git view's right-click menus, through a real IDE.
 *
 * Lives here rather than in core's `IdeContextMenusTest` because the widgets it
 * drives are this plugin's: the coverage gate grades `plugins/` from the
 * plugin's OWN tests, so a core E2e covering plugin code reads as covered in
 * one pass and absent in the other.
 */
final class GitContextMenuE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    public function testGitHistoryCommitContextMenuOffersCommitActions(): void
    {
        if (!$this->gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        \putenv('PATH='.\dirname((string) $this->whichGit()));
        $project = new TempProject(['src/A.php' => "<?php\n\$a = 1;\n", '.porta.json' => '{"enabledPlugins":["diff","git"]}']);
        $project->initGit();
        $project->commitAll('init');

        $this->runIde(
            $project,
            $project->path('src/A.php'),
            [
                300 => static function ($tui): void {
                    $tui->handleInput("\x1b\x04"); // ctrl+alt+d opens the git view
                },
                550 => function (Tui $tui, $terminal, Ide $ide): void {
                    $this->await(static fn (): bool => 'git-changes' === $tui->getFocus()?->getId());
                    $tui->handleInput('2'); // switch to the History tab
                    $this->await(static fn (): bool => null !== Widgets::byId($tui, 'git-history', HistoryListWidget::class)->selectedCommit());

                    $renderer = Services::resolve($ide, Renderer::class);
                    $rect = Widgets::rect($renderer, $tui->getById('git-history'));
                    $terminal->clearOutput();
                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $rect->col + 8, $rect->row + 2)); // right-click the first commit row (SGR is 1-based)
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'right-clicking a commit opened its menu');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Cherry-pick'));
                    $screen = AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->assertStringContainsString('Copy Hash', $screen);
                    $this->assertStringContainsString('Reset to Commit', $screen);
                    $tui->handleInput("\x1b"); // close the menu
                },
                900 => function (Tui $tui, $terminal): void {
                    $this->frame($tui);
                    // Keyboard parity: Ctrl+F10 on the (focused) history list.
                    $this->assertSame('git-history', $tui->getFocus()?->getId());
                    $terminal->clearOutput();
                    $tui->handleInput("\x1b[21;5~");
                    $this->assertTrue(Widgets::byId($tui, 'context-menu', ContextMenu::class)->isOpen(), 'Ctrl+F10 opened the commit menu');
                    $this->await(static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'Copy Hash'));
                    $this->assertStringContainsString('Copy Hash', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
                },
            ],
        );
    }
}
