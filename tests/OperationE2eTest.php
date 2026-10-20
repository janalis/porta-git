<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\DiffPlugin\Merge\MergePaneWidget;
use Porta\Editor\EditorPanes;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A rebase stopped on a conflict, all the way through in a running IDE: the
 * status bar says so, Continue waits for the conflict, the merge view
 * resolves it, and Continue then finishes the rebase.
 */
final class OperationE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    /** On main, replaying its one commit onto topic, stopped on a.php. */
    private function rebasing(): TempProject
    {
        $this->skipWithoutGit();
        \putenv('PATH='.\PORTA_TEST_PRISTINE_PATH);
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["diff","git"]}', 'a.php' => "<?php\necho 'base';\n"]);
        $project->initGit(branch: 'main');
        $project->commitAll('base');
        $project->git('checkout', '-q', '-b', 'topic');
        $project->write('a.php', "<?php\necho 'theirs';\n");
        $project->commitAll('theirs');
        $project->git('checkout', '-q', 'main');
        $project->write('a.php', "<?php\necho 'ours';\n");
        $project->commitAll('ours');
        new Process(['git', 'rebase', 'topic'], $project->dir, ['PATH' => (string) \PORTA_TEST_PRISTINE_PATH, 'GIT_EDITOR' => 'true'])->run();
        $this->assertDirectoryExists($project->path('.git/rebase-merge'));

        return $project;
    }

    public function testARebaseStoppedOnAConflictIsFinishedFromTheIde(): void
    {
        $project = $this->rebasing();

        $this->runIde(
            $project,
            $project->path('a.php'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $this->awaitPainted($tui, $terminal, 'rebasing 1/1 · 1 conflict');
                    $commands = Services::resolve($ide, CommandRegistry::class);

                    $commands->run('git:continue');
                    $this->await(static fn (): bool => \in_array('Resolve the conflicts first: 1 left', Toasts::all($ide), strict: true), what: 'continue refused');

                    // The merge view. Mid-rebase git's "ours" is the branch
                    // being rebased onto and "theirs" the commit being
                    // replayed — so it is theirs, on the right, that keeps
                    // this branch's line; the labels say which is which.
                    $this->awaitPainted($tui, $terminal, '⇤ ');
                    $theirs = Services::resolve($ide, EditorPanes::class)->paneFor(MergePaneWidget::PANE_ID);
                    $this->assertInstanceOf(MergePaneWidget::class, $theirs);
                    $tui->setFocus($theirs);
                    $tui->handleInput('a');
                    $tui->handleInput("\x13"); // Ctrl+S, from the side
                    $this->await(static fn (): bool => "<?php\necho 'ours';\n" === \file_get_contents($project->path('a.php')), what: 'the result saved');

                    $project->git('add', 'a.php');
                    $commands->run('git:continue');
                    $this->awaitPainted($tui, $terminal, 'Continued the rebase');
                    $this->await(static fn (): bool => !\is_dir($project->path('.git/rebase-merge')), what: 'the rebase finished');
                    $this->assertSame('ours', $project->git('log', '-1', '--pretty=%s'), 'the commit replayed, its message kept');
                },
            ],
        );
    }
}
