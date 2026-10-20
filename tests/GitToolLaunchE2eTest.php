<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Container\ToolLaunch;
use Porta\Event\ChangeProposed;
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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
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
        $this->skipWithoutGit();
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH); // git diff --no-index needs git
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["diff","git"]}', 'a.php' => "<?php\necho 'working tree';\n"]);
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

    public function testAnAgentsProposedChangeIsComparedAndClosingItEndsNothingButTheView(): void
    {
        $this->skipWithoutGit();
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH);
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["diff","git"]}', 'a.php' => "<?php\necho 'as it is';\n"]);
        $project->initGit();
        $project->commitAll('init');
        $proposal = new TempProject(['a.php' => "<?php\necho 'as proposed';\n"]);

        $this->runIde($project, null, [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project, $proposal): void {
                $views = Services::resolve($ide, ViewRegistry::class);
                Services::resolve($ide, CommandRegistry::class)->run('ide_welcome');
                $this->assertTrue($views->isOpen('welcome'), 'a modal is up when the proposal comes');
                Services::resolve($ide, EventDispatcherInterface::class)->dispatch(new ChangeProposed($project->path('a.php'), $proposal->path('a.php')));
                $this->awaitPainted($tui, $terminal, "echo 'as it is';");
                $this->awaitPainted($tui, $terminal, "echo 'as proposed';");
                $view = $views->find('git');
                $this->assertTrue($view?->isOpen(), 'side by side in the git view');
                $this->assertFalse($views->isOpen('welcome'), 'which the modal yields to, rather than keeping the keys underneath');

                $tui->handleInput("\x1b");
                $this->await(static fn (): bool => false === $view?->isOpen(), what: 'Esc to close it');
                $this->assertTrue($tui->isRunning(), 'and Porta goes on — this was no difftool run');
            },
        ]);
    }

    public function testAMergetoolLaunchOpensTheConflictedFileInTheMergeView(): void
    {
        $this->skipWithoutGit();
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH);
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["diff","git"]}', 'a.php' => "<?php\necho 'base';\n"]);
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
                    // Ours | result | theirs, labelled by the markers git wrote.
                    $this->awaitPainted($tui, $terminal, '⇤ feature');
                    $grid = \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->assertStringContainsString('⇥ HEAD', $grid);
                    $this->assertStringContainsString('<<<<<<< HEAD', $grid, 'the result, in the editor');
                    $this->await(static fn (): bool => \array_any(Toasts::all($ide), static fn (string $toast): bool => \str_contains(
                        $toast,
                        'a keeps a side',
                    )), what: 'the hint saying how to resolve and finish');
                    $this->assertNotTrue(Services::resolve($ide, ViewRegistry::class)->find('git')?->isOpen(), 'nothing over it');
                },
            ],
            tool: ToolLaunch::merge($project->path('a_LOCAL.php'), $project->path('a_REMOTE.php'), $project->path('a_BASE.php'), $project->path('a.php')),
        );
    }
}
