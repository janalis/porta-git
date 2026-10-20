<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Command;

use PHPUnit\Framework\TestCase;
use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\Command\OperationCommands;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitOperation;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\OperationState;
use Porta\GitPlugin\Tests\Support\DrivesPickers;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\I18n\Translator;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Process\Process;

/**
 * Continue, skip and abort against a real repository stopped on a real
 * conflict — and the refresher reading which operation it is from git's own
 * files.
 */
final class OperationCommandsTest extends TestCase
{
    use DrivesPickers;
    use GitBinary;

    private TempProject $project;
    private GitRefresher $refresher;
    private int $moved = 0;
    private ?string $savedEditor = null;

    protected function setUp(): void
    {
        $this->skipWithoutGit();
        $editor = \getenv('GIT_EDITOR');
        $this->savedEditor = false === $editor ? null : $editor;
        // An editor that would fail: a continue that opened one could not
        // succeed, so passing proves it never did.
        \putenv('GIT_EDITOR=false');
    }

    protected function tearDown(): void
    {
        \putenv(null === $this->savedEditor ? 'GIT_EDITOR' : 'GIT_EDITOR='.$this->savedEditor);
    }

    /** a.txt changed on both sides of $command, which stops on it. */
    private function stopped(string ...$command): OperationCommands
    {
        $this->project = new TempProject(['a.txt' => "base\n", 'b.txt' => "b\n"]);
        $this->project->initGit(branch: 'main');
        $this->project->commitAll('base');
        $this->project->git('checkout', '-q', '-b', 'topic');
        $this->project->write('a.txt', "theirs\n");
        $this->project->commitAll('theirs');
        $this->project->write('b.txt', "b2\n");
        $this->project->commitAll('also theirs');
        $this->project->git('checkout', '-q', 'main');
        $this->project->write('a.txt', "ours\n");
        $this->project->commitAll('ours');
        if ([] !== $command) {
            new Process(['git', ...$command], $this->project->dir, ['PATH' => (string) \PORTA_TEST_PRISTINE_PATH, 'GIT_EDITOR' => 'true'])->run();
        }

        return $this->build();
    }

    private function build(): OperationCommands
    {
        $git = new GitInfo($this->project->dir);
        $this->editor = new CodeEditorWidget();
        $this->statusBar = new StatusBarWidget();
        $tui = TestTui::create(100, 24);
        $this->refresher = new GitRefresher($git, static function (): void {});
        $events = new EventDispatcher();
        $events->addListener(WorkingTreeChanged::class, function (): void {
            ++$this->moved;
        });

        return new OperationCommands(
            $git,
            $this->refresher,
            GitDoubles::modals($this->editor),
            GitDoubles::flash(new Notifier($this->statusBar, $tui)),
            GitDoubles::translate(new Translator('en')),
            $events,
            $tui->requestRender(...),
        );
    }

    /** Ask git again and wait until the refresher's answer is $expected. */
    private function refreshed(?OperationState $expected): void
    {
        $this->refresher->refreshBranch();
        // Compared whole — operation, step, total, conflicts — as values.
        $this->await(fn (): bool => \serialize($expected) === \serialize($this->refresher->operation()), what: 'the operation read off git');
    }

    private function resolve(string $text): void
    {
        $this->project->write('a.txt', $text);
        $this->project->git('add', 'a.txt');
    }

    public function testARebaseStoppedOnAConflictIsReadWithItsStepAndConflicts(): void
    {
        // On main, replaying its one commit onto topic: step 1 of 1, stopped on a.txt.
        $this->stopped('rebase', 'topic');

        $this->refreshed(new OperationState(GitOperation::Rebasing, 1, 1, 1));
        $this->assertDirectoryExists($this->project->path('.git/rebase-merge'));
    }

    public function testContinueIsRefusedWhileAConflictIsLeft(): void
    {
        $commands = $this->stopped('merge', 'topic');
        $this->refreshed(new OperationState(GitOperation::Merging, conflicts: 1));

        $commands->continue();
        $this->pumpUntil();

        $this->assertStringContainsString('Resolve the conflicts first: 1 left', $this->statusRow($this->statusBar));
        $this->assertFileExists($this->project->path('.git/MERGE_HEAD'), 'git was not asked');
    }

    public function testAConflictResolvedBehindTheIdesBackDoesNotHoldContinueUp(): void
    {
        // Staged from a terminal: the refresher still counts the conflict,
        // git does not.
        $commands = $this->stopped('merge', 'topic');
        $this->refreshed(new OperationState(GitOperation::Merging, conflicts: 1));
        $this->resolve("both\n");

        $commands->continue();
        $this->pumpUntil();

        $this->assertStringContainsString('Continued the merge', $this->statusRow($this->statusBar));
    }

    public function testAResolvedMergeContinuesWithNoEditorToWaitOn(): void
    {
        $commands = $this->stopped('merge', 'topic');
        $this->resolve("both\n");
        $this->refreshed(new OperationState(GitOperation::Merging));

        $commands->continue();
        $this->pumpUntil();
        $this->refreshed(null);

        $this->assertStringContainsString('Continued the merge', $this->statusRow($this->statusBar));
        $this->assertStringContainsString('Merge branch', $this->project->git('log', '-1', '--pretty=%s'), 'git\'s own message, kept');
        $this->assertGreaterThan(0, $this->moved, 'the working tree said moved');
    }

    public function testAMergeHasNoStepToSkip(): void
    {
        $commands = $this->stopped('merge', 'topic');
        $this->refreshed(new OperationState(GitOperation::Merging, conflicts: 1));

        $commands->skip();

        $this->assertStringContainsString('A merge has no step to skip', $this->statusRow($this->statusBar));
    }

    public function testARebaseSkipsTheStepThatStopped(): void
    {
        $commands = $this->stopped('rebase', 'topic');
        $this->refreshed(new OperationState(GitOperation::Rebasing, 1, 1, 1));

        $commands->skip();
        $this->pumpUntil();

        $this->assertStringContainsString('Skipped the step and continued the rebase', $this->statusRow($this->statusBar));
        $this->refreshed(null);
        $this->assertSame("theirs\n", (string) \file_get_contents($this->project->path('a.txt')), 'main\'s conflicting commit dropped, topic\'s side left');
    }

    public function testAbortAsksFirstThenPutsEverythingBack(): void
    {
        $commands = $this->stopped('cherry-pick', 'topic~1');
        $this->refreshed(new OperationState(GitOperation::CherryPicking, conflicts: 1));

        $commands->abort();
        $this->overlayOpens();
        $this->key("\r"); // Abort, the first choice
        $this->pumpUntil();

        $this->assertStringContainsString('Aborted the cherry-pick', $this->statusRow($this->statusBar));
        $this->refreshed(null);
        $this->assertSame("ours\n", (string) \file_get_contents($this->project->path('a.txt')));
    }

    public function testCancellingTheAbortKeepsTheOperation(): void
    {
        $commands = $this->stopped('revert', '--no-edit', 'main~1');
        $this->refreshed(new OperationState(GitOperation::Reverting, conflicts: 1));

        $commands->abort();
        $this->overlayOpens();
        $this->key("\x1b[B"); // Cancel
        $this->key("\r");
        $this->drain();

        $this->refreshed(new OperationState(GitOperation::Reverting, conflicts: 1));
        $this->assertFileExists($this->project->path('.git/REVERT_HEAD'), 'git was not asked');
    }

    public function testWhatGitRefusesIsSaidInItsWords(): void
    {
        // The refresher still says merging, git has finished meanwhile: git
        // refuses, and its reason is what the user reads.
        $commands = $this->stopped();
        Probe::set($this->refresher, 'operation', new OperationState(GitOperation::Merging));

        $commands->continue();
        $this->pumpUntil();

        $this->assertStringContainsString('MERGE_HEAD missing', $this->statusRow($this->statusBar));
    }

    public function testWithNothingInProgressEachSaysSo(): void
    {
        $commands = $this->stopped();
        $this->refreshed(null);

        foreach ([$commands->continue(...), $commands->skip(...), $commands->abort(...)] as $verb) {
            $verb();
            $this->assertStringContainsString('No merge or rebase in progress', $this->statusRow($this->statusBar));
        }
    }
}
