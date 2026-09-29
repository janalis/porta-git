<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Container\ToolLaunch;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Porta launched as git's difftool and mergetool, answered by the git plugin:
 * the comparison in the git view (and closing it ending the run git waits on),
 * and a merge opened on its conflicted file.
 */
final class GitToolLaunchE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    public function testADifftoolLaunchShowsTheTwoFilesAndClosingItEndsTheRun(): void
    {
        self::skipWithoutGit();
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH); // git diff --no-index needs git
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}', 'a.php' => "<?php\necho 'working tree';\n"]);
        $project->initGit();
        $project->commitAll('init');
        // Git hands the old side over as a temp blob OUTSIDE the repository.
        $blob = new TempProject(['a_LOCAL_123.php' => "<?php\necho 'committed';\n"]);

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, "echo 'working tree';");
                    $this->awaitPainted($tui, $terminal, "echo 'committed';");
                    $this->assertTrue(Services::resolve($ide, ViewRegistry::class)->find('git')?->isOpen(), 'the git view shows the comparison');
                    $this->assertStringContainsString('a.php', \implode("\n", $this->screenGrid($tui, $terminal)), 'titled with the file git is diffing, not the blob');

                    $tui->handleInput("\x1b");
                    $this->await(static fn (): bool => !$tui->isRunning(), what: 'Esc to end the difftool run');
                },
            ],
            tool: ToolLaunch::diff($blob->path('a_LOCAL_123.php'), $project->path('a.php')),
        );
    }

    public function testAMergetoolLaunchOpensTheConflictedFileUnderTheGitView(): void
    {
        self::skipWithoutGit();
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH);
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}', 'a.php' => "<?php\necho 'base';\n"]);
        $project->initGit(branch: 'main');
        $project->commitAll('base');
        $project->git('checkout', '-q', '-b', 'feature');
        \file_put_contents($project->path('a.php'), data: "<?php\necho 'theirs';\n");
        $project->commitAll('theirs');
        $project->git('checkout', '-q', 'main');
        \file_put_contents($project->path('a.php'), data: "<?php\necho 'ours';\n");
        $project->commitAll('ours');
        // The merge stops on the conflict, which is when git runs a mergetool.
        new Process(['git', 'merge', '-q', '--no-edit', 'feature'], $project->dir, ['PATH' => (string) \PORTA_TEST_PRISTINE_PATH])->run();
        $this->assertStringContainsString('<<<<<<<', (string) \file_get_contents($project->path('a.php')));

        $this->runIde(
            $project,
            null,
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->await(static fn (): bool => true === Services::resolve($ide, ViewRegistry::class)->find('git')?->isOpen(), what: 'the git view');
                    $this->await(static fn (): bool => \array_any(Toasts::all($ide), static fn (string $toast): bool => \str_contains(
                        $toast,
                        'Alt+M resolve',
                    )), what: 'the hint saying how to resolve and finish');

                    // The conflicted file's diff, against our side: a plain `git diff`
                    // of an unmerged path is a combined diff the pane cannot read.
                    // Read off the CURRENT screen: the editor painted the markers
                    // before the view covered it, so the output so far proves nothing.
                    $this->await(fn (): bool => \str_contains(\implode("\n", $this->screenGrid($tui, $terminal)), '<<<<<<< HEAD'), what: 'the conflict in the diff pane');

                    $tui->handleInput("\x1b"); // close the view: the conflicted file is underneath
                    $this->awaitPainted($tui, $terminal, "echo 'theirs';");
                    $this->assertFalse(Services::resolve($ide, ViewRegistry::class)->find('git')?->isOpen(), 'the editor, not the view');
                },
            ],
            tool: ToolLaunch::merge($project->path('a_LOCAL.php'), $project->path('a_REMOTE.php'), $project->path('a_BASE.php'), $project->path('a.php')),
        );
    }
}
