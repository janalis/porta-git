<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Worktrees through a booted IDE: the palette command a plugin registers,
 * `PluginApi::openProject()` and the runtime that carries the switcher to
 * it — the join no unit test makes, since each of those is a double there.
 */
final class WorktreeE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    private TempProject $project;

    /** The linked worktree: git adds one into an empty folder, so it can be a project the harness boots */
    private TempProject $linked;

    protected function setUp(): void
    {
        $this->skipWithoutGit();
        $this->project = new TempProject(['src/A.php' => "<?php\n", '.porta.json' => '{"enabledPlugins":["diff","git"]}']);
        $this->project->initGit(branch: 'main');
        // Untracked, so a worktree checks out no `.porta.json` — one would
        // put the trust question between the pick and the switch.
        $this->project->write('.git/info/exclude', ".porta/\n.porta.json\n");
        $this->project->commitAll('init');
        $this->linked = new TempProject([]);
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->linked->dir);
    }

    public function testPickingAWorktreeReopensTheIdeThere(): void
    {
        $this->runIde(
            $this->project,
            $this->project->path('src/A.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $editor = Services::resolve($ide, CodeEditorWidget::class);
                    $this->palette($tui, 'Git: Worktrees');
                    $this->await(static fn (): bool => EditorState::pickerOpen($editor), what: 'the worktree list');
                    // Its folder's name: the list is filtered by what is typed.
                    foreach (\str_split(\basename($this->linked->dir)) as $ch) {
                        $tui->handleInput($ch);
                    }
                    $tui->handleInput("\r");
                    $linked = (string) \realpath($this->linked->dir);
                    $this->await(static fn (): bool => $linked === $ide->reopenAt(), what: 'the switch to the worktree');
                },
            ],
        );
    }

    public function testInALinkedWorktreeTheStatusBarSaysWhich(): void
    {
        $this->linked->write('.porta.json', '{"enabledPlugins":["diff","git"]}');
        $name = \basename($this->linked->dir);

        $this->runIde(
            $this->linked,
            $this->linked->path('src/A.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal) use ($name): void {
                    $screen = static fn (): string => AnsiUtils::stripAnsiCodes($terminal->getOutput());
                    $this->await(static fn (): bool => \str_contains($screen(), 'feat ❐ '.$name), what: 'the branch cell to name the worktree');
                },
            ],
        );
    }

    private function palette(Tui $tui, string $label): void
    {
        $tui->handleInput("\x1b[97;6u");
        foreach (\str_split($label) as $ch) {
            $tui->handleInput($ch);
        }
        $tui->handleInput("\r");
    }
}
