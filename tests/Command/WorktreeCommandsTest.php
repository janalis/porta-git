<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Command;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Command\WorktreeCommands;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\Tests\Support\DrivesPickers;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\I18n\Translator;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Every {@see WorktreeCommands} flow against a real repository, through the
 * real list and prompt — what git does with each answer is the assertion,
 * read back with git itself.
 *
 * New worktrees go to a folder of their own (the `worktreeFolder` setting),
 * so nothing lands beside the temp project where no one cleans up.
 */
final class WorktreeCommandsTest extends TestCase
{
    use GitBinary;
    use PreservesPortaHome;
    use DrivesPickers;

    private TempProject $project;
    private TempProject $folder;
    private string $setting = '';
    private ?string $gitPath = null;

    /** @var list<array{string, string}> what the commands asked core to open, with the repeat clause */
    private array $opened = [];

    protected function setUp(): void
    {
        $this->skipWithoutGit();
        $this->project = new TempProject(['a.txt' => "one\n"]);
        $this->project->initGit(branch: 'main');
        $this->project->commitAll('init');
        $this->folder = new TempProject([]);
        $this->setting = $this->folder->dir;
    }

    // --- The list -------------------------------------------------------------

    public function testTheListShowsEachWorktreeAndMarksTheOneOpen(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));

        $this->commands()->worktrees();
        $this->overlayOpens();

        $rows = $this->rows();
        $main = $rows[\basename($this->project->dir)] ?? null;
        $this->assertNotNull($main, 'the main worktree is listed by its folder name');
        $this->assertStringStartsWith('✓ ', $main['label'], 'the one this session has open is marked');
        $this->assertSame('main · main', $main['hint'], 'its branch, then that it is the main one — and no path, which crowded the name out of the row');
        $this->assertSame('  feat', $rows['feat']['label']);
        $this->assertSame('feat', $rows['feat']['hint'], 'its branch alone: a linked worktree is not called main');
        $this->assertSame('Worktrees', $this->overlay()['title']);
    }

    public function testPickingAWorktreeAsksCoreToOpenIt(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));

        $this->commands()->worktrees();
        $this->overlayOpens();
        $this->type('feat');
        $this->key("\r");

        $this->assertCount(1, $this->opened);
        $this->assertSame(\realpath($this->at('feat')), \realpath($this->opened[0][0]));
        // Not "pick it again": the list is closed by then, and the guard's
        // seconds are gone before a fresh one has listed.
        $this->assertSame('Ctrl+S to save, then switch worktree', $this->opened[0][1], 'the unsaved-changes refusal says what to do HERE');
    }

    public function testAWorktreeWhoseFolderIsGoneCannotBeOpenedButCanBeForgotten(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'gone', $this->at('gone'));
        new Filesystem()->remove($this->at('gone'));

        $this->commands()->worktrees();
        $this->overlayOpens();

        $rows = $this->rows();
        $this->assertTrue($rows['gone']['disabled'] ?? false, 'nothing to open');
        $this->assertSame('gone · folder missing', $rows['gone']['hint']);

        $this->type('Forget');
        $this->key("\r");
        $this->pumpUntil();

        $this->assertStringContainsString('Forgot the missing worktrees', $this->statusRow($this->statusBar));
        $this->assertStringNotContainsString('gone', $this->project->git('worktree', 'list'));
    }

    public function testWithNoMissingWorktreeThereIsNothingToForget(): void
    {
        $this->commands()->worktrees();
        $this->overlayOpens();

        $this->assertArrayNotHasKey('Forget missing worktrees', $this->rows());
    }

    public function testOutsideARepositoryItSaysGitIsNotAvailable(): void
    {
        $this->project = new TempProject([]);

        $this->commands()->worktrees();

        $this->assertStringContainsString('Git not available', $this->statusRow($this->statusBar));
        $this->assertFalse(EditorState::pickerOpen($this->editor));
    }

    public function testAGitThatCannotListWorktreesSaysWhatGitSaid(): void
    {
        $this->gitPath = $this->fakeGit('[ "$a" = worktree ] && { echo "fatal: not a git repository" >&2; exit 128; }');

        $this->commands()->newWorktree();
        $this->pumpUntil();

        $this->assertStringContainsString('Could not list worktrees: fatal: not a git repository', $this->statusRow($this->statusBar));
        $this->assertFalse(EditorState::promptOpen($this->editor), 'nothing is asked that could not be done');
    }

    public function testAGitOlderThanMinusZListsWithoutIt(): void
    {
        // Before 2.36 git refuses `-z` outright (Ubuntu 22.04 ships 2.34).
        $this->gitPath = $this->fakeGit('[ "$a" = -z ] && { echo "error: unknown switch \\`z\'" >&2; exit 129; }');
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));

        $this->commands()->worktrees();
        $this->overlayOpens();

        $this->assertSame('feat', $this->rows()['feat']['hint'] ?? null, 'listed all the same, from the plain form');
    }

    // --- Adding ---------------------------------------------------------------

    public function testANewWorktreeOnANewBranchNamedAfterIt(): void
    {
        $this->commands()->newWorktree();
        $this->promptOpens();
        $this->type('topic');
        $this->key("\r");
        $this->overlayOpens();

        $this->assertStringEndsWith('/'.\basename($this->folder->dir).'/topic', $this->overlay()['title'], 'the title says where the folder goes');
        $this->assertSame('+ New branch topic', $this->overlay()['items'][0]['label']);
        $this->key("\r");
        $this->pumpUntil(fn (): bool => \str_starts_with($this->overlay()['title'] ?? '', 'Open'), what: 'the open-or-stay question');

        $this->assertStringContainsString('Created worktree topic', $this->statusRow($this->statusBar));
        $this->assertSame('topic', \trim($this->gitIn($this->at('topic'), 'rev-parse', '--abbrev-ref', 'HEAD')));
        $this->assertSame('Open topic?', $this->overlay()['title']);
        $this->key("\r"); // Open
        $this->assertSame($this->at('topic'), $this->opened[0][0] ?? null);
    }

    public function testALongPathGivesUpItsStartInTheTitle(): void
    {
        // The title is cut at its end, which is the folder being named.
        $deep = new TempProject([]);
        $folder = $deep->dir.'/'.\str_repeat('nested/', times: 8).'parent';
        new Filesystem()->mkdir($folder);
        $this->setting = $folder;

        $this->commands()->newWorktree();
        $this->promptOpens();
        $this->type('topic');
        $this->key("\r");
        $this->overlayOpens();

        $this->assertSame('Branch for …/parent/topic', $this->overlay()['title']);
    }

    public function testStayingAfterCreatingOpensNothing(): void
    {
        $this->commands()->newWorktree();
        $this->promptOpens();
        $this->type('topic');
        $this->key("\r");
        $this->overlayOpens();
        $this->key("\r");
        $this->pumpUntil(fn (): bool => \str_starts_with($this->overlay()['title'] ?? '', 'Open'), what: 'the open-or-stay question');
        $this->type('Stay');
        $this->key("\r");

        $this->assertSame([], $this->opened);
        $this->assertDirectoryExists($this->at('topic'));
    }

    public function testANameAnotherWorktreeHasOutIsNoNewBranch(): void
    {
        // git would refuse both: a new branch of that name, and that branch.
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));
        $this->project->git('branch', 'other');

        $this->commands()->newWorktree();
        $this->promptOpens();
        $this->type('feat');
        $this->key("\r");
        $this->overlayOpens();

        $items = $this->overlay()['items'];
        $this->assertSame('feat is checked out in another worktree', $items[0]['label']);
        $this->assertTrue($items[0]['note'] ?? false, 'read, not run');
        $this->assertNotContains('+ New branch feat', \array_column($items, 'label'));
        $this->assertContains('  other', \array_column($items, 'label'), 'another branch still can be');
    }

    public function testAnExistingFreeBranchIsCheckedOutRatherThanCreated(): void
    {
        // Desktop: a branch name that exists is that branch.
        $this->project->git('branch', 'topic');

        $this->commands()->newWorktree();
        $this->promptOpens();
        $this->type('topic');
        $this->key("\r");
        $this->overlayOpens();

        $labels = \array_column($this->overlay()['items'], 'label');
        $this->assertSame('  topic', $labels[0], 'the branch of that name leads, and no new one is offered');
        $this->assertNotContains('+ New branch topic', $labels);
        $this->key("\r");
        $this->pumpUntil();

        $this->assertSame('topic', \trim($this->gitIn($this->at('topic'), 'rev-parse', '--abbrev-ref', 'HEAD')));
    }

    public function testAnotherLocalBranchCanBeChosen(): void
    {
        $this->project->git('branch', 'other');

        $this->commands()->newWorktree();
        $this->promptOpens();
        $this->type('wt');
        $this->key("\r");
        $this->overlayOpens();
        $labels = \array_column($this->overlay()['items'], 'label');
        $this->assertNotContains('  main', $labels, 'main is checked out in the main worktree, and git would refuse it');
        $this->type('other');
        $this->key("\r");
        $this->pumpUntil();

        $this->assertSame('other', \trim($this->gitIn($this->at('wt'), 'rev-parse', '--abbrev-ref', 'HEAD')));
    }

    public function testARemoteBranchGetsALocalOneOfItsShortName(): void
    {
        $this->project->git('update-ref', 'refs/remotes/origin/feature/x', 'HEAD');

        $this->commands()->newWorktree();
        $this->promptOpens();
        $this->type('wt');
        $this->key("\r");
        $this->overlayOpens();
        $this->type('origin/feature');
        $this->key("\r");
        $this->pumpUntil();

        $this->assertSame('feature/x', \trim($this->gitIn($this->at('wt'), 'rev-parse', '--abbrev-ref', 'HEAD')));
    }

    public function testAnEmptyNameAddsNothing(): void
    {
        $this->commands()->newWorktree();
        $this->promptOpens();
        $this->type('   ');
        $this->key("\r");
        $this->drain();

        $this->assertFalse(EditorState::pickerOpen($this->editor));
        $this->assertCount(1, $this->listed());
    }

    public function testAFolderThatExistsIsGitsToRefuse(): void
    {
        $this->folder->write('topic/keep.txt', 'x');

        $this->commands()->newWorktree();
        $this->promptOpens();
        $this->type('topic');
        $this->key("\r");
        $this->overlayOpens();
        $this->key("\r");
        $this->pumpUntil();

        // git's "Preparing worktree" comes first on stderr; the reason is the fatal line.
        $this->assertStringContainsString('already exists', $this->statusRow($this->statusBar));
        $this->assertStringNotContainsString('Preparing', $this->statusRow($this->statusBar));
    }

    public function testWithNoFolderSetANewWorktreeGoesBesideTheMainOne(): void
    {
        $this->setting = '';
        $name = \basename($this->project->dir).'-beside';
        $this->commands()->newWorktree();
        $this->promptOpens();
        $this->type($name);
        $this->key("\r");
        $this->overlayOpens();

        $parent = \basename(\dirname((string) \realpath($this->project->dir)));
        $this->assertStringEndsWith('/'.$parent.'/'.$name, $this->overlay()['title']);
        $this->assertStringNotContainsString(\basename($this->folder->dir), $this->overlay()['title'], 'not the folder setting, which is empty');
    }

    public function testARelativeFolderIsTheRepositorysAndOpensFromThere(): void
    {
        // git reads it from the repository; an open reads it from wherever
        // Porta was started. Made whole, both agree.
        $this->setting = '../'.\basename($this->folder->dir);
        $this->commands()->newWorktree();
        $this->promptOpens();
        $this->type('topic');
        $this->key("\r");
        $this->overlayOpens();
        $this->key("\r");
        $this->pumpUntil(fn (): bool => \str_starts_with($this->overlay()['title'] ?? '', 'Open'), what: 'the open-or-stay question');
        $this->key("\r"); // Open

        $this->assertDirectoryExists($this->at('topic'));
        $this->assertStringStartsWith('/', $this->opened[0][0] ?? '', 'whole, not relative to the working directory');
        $this->assertSame(\realpath($this->at('topic')), \realpath($this->opened[0][0]));
    }

    public function testATildeInTheFolderSettingIsHome(): void
    {
        \putenv('PORTA_HOME='.\dirname($this->folder->dir));
        $this->setting = '~/'.\basename($this->folder->dir).'/';
        $this->commands()->newWorktree();
        $this->promptOpens();
        $this->type('topic');
        $this->key("\r");
        $this->overlayOpens();

        $this->assertSame('Branch for ~/'.\basename($this->folder->dir).'/topic', $this->overlay()['title'], 'shown from ~ again');
        $this->key("\r");
        $this->pumpUntil();
        $this->assertDirectoryExists($this->at('topic'));
    }

    // --- Deleting -------------------------------------------------------------

    public function testDeletingAWorktreeTakesItsFolder(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));

        $this->pickAction('Delete');
        $this->type('feat');
        $this->key("\r");
        $this->assertSame('Delete feat? Its folder goes with it', $this->overlay()['title']);
        $this->key("\r"); // Delete
        $this->pumpUntil();

        $this->assertStringContainsString('Deleted worktree feat', $this->statusRow($this->statusBar));
        $this->assertDirectoryDoesNotExist($this->at('feat'));
        $this->assertStringContainsString('feat', $this->project->git('branch'), 'the branch stays, as with git and Desktop');
    }

    public function testAWorktreeWithChangesIsDeletedOnlyWhenAskedAgain(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));
        \file_put_contents($this->at('feat').'/new.txt', data: 'unsaved work');

        $this->pickAction('Delete');
        $this->key("\r"); // feat
        $this->key("\r"); // Delete
        $this->pumpUntil(fn (): bool => \str_contains($this->overlay()['title'] ?? '', 'has changes'), what: 'the second question');

        $this->assertDirectoryExists($this->at('feat'), 'git refused, and nothing is gone yet');
        $this->assertSame('feat has changes. Delete it anyway, and them?', $this->overlay()['title']);
        $this->key("\r"); // Delete anyway
        $this->pumpUntil();

        $this->assertStringContainsString('Deleted worktree feat', $this->statusRow($this->statusBar));
        $this->assertDirectoryDoesNotExist($this->at('feat'));
    }

    public function testAPathThatSpellsTheFlagIsNotTakenForGitAskingToForce(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'odd', $this->at('x--force'));
        $commands = $this->commands();
        $commands->worktrees();
        $this->overlayOpens();
        $this->type('Delete');
        $this->key("\r");
        $this->overlayOpens();
        // Gone behind the list's back: git's refusal names the path, which
        // holds `--force`, and asks for nothing of the kind.
        new Filesystem()->remove($this->at('x--force'));
        $this->project->git('worktree', 'prune');
        $this->key("\r");
        $this->key("\r"); // Delete
        $this->pumpUntil();

        $this->assertStringContainsString('is not a working tree', $this->statusRow($this->statusBar));
        $this->assertStringNotContainsString('anyway', $this->overlay()['title'] ?? '');
    }

    public function testCancellingTheDeleteKeepsTheWorktree(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));

        $this->pickAction('Delete');
        $this->key("\r");
        $this->type('Cancel');
        $this->key("\r");
        $this->drain();

        $this->assertDirectoryExists($this->at('feat'));
    }

    public function testTheMainTheOpenAndALockedWorktreeCannotBeDeleted(): void
    {
        // The open one: Desktop switches to main and then deletes, and here
        // the switch would end the session the delete runs in.
        $this->project->git('worktree', 'add', '-q', '-b', 'here', $this->at('here'));
        $this->project->git('worktree', 'add', '-q', '-b', 'held', $this->at('held'));
        $this->project->git('worktree', 'lock', $this->at('held'));

        $this->pickAction('Delete', root: $this->at('here'));

        $this->assertStringContainsString('No other worktrees to delete', $this->statusRow($this->statusBar));
    }

    public function testALockedWorktreeIsListedAsLocked(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'held', $this->at('held'));
        $this->project->git('worktree', 'lock', $this->at('held'));

        $this->commands()->worktrees();
        $this->overlayOpens();

        $this->assertSame('held · locked', $this->rows()['held']['hint']);
    }

    // --- Renaming -------------------------------------------------------------

    public function testRenamingMovesTheFolderBesideItself(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));

        $this->pickAction('Rename');
        $this->key("\r"); // feat
        $this->promptOpens();
        $this->clearPrompt('feat');
        $this->type('renamed');
        $this->key("\r");
        $this->pumpUntil();

        $this->assertStringContainsString('Renamed worktree to renamed', $this->statusRow($this->statusBar));
        $this->assertDirectoryExists($this->at('renamed'));
        $this->assertDirectoryDoesNotExist($this->at('feat'));
    }

    public function testRenamingOntoAFolderThatExistsIsRefusedBeforeGitBuriesIt(): void
    {
        // Measured: `worktree move` onto an existing folder moves it INTO it.
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));
        $this->folder->write('taken/keep.txt', 'x');

        $this->pickAction('Rename');
        $this->key("\r");
        $this->promptOpens();
        $this->clearPrompt('feat');
        $this->type('taken');
        $this->key("\r");
        $this->pumpUntil();

        $this->assertStringContainsString('already exists', $this->statusRow($this->statusBar));
        $this->assertDirectoryExists($this->at('feat'), 'left where it was');
        $this->assertDirectoryDoesNotExist($this->at('taken/feat'));
    }

    public function testRenamingToTheSameNameDoesNothing(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));

        $this->pickAction('Rename');
        $this->key("\r");
        $this->promptOpens();
        $this->key("\r"); // the prefilled name, unchanged
        $this->drain();

        $this->assertSame('', \trim($this->statusRow($this->statusBar)));
    }

    public function testARenameGitRefusesSaysWhy(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));

        $this->pickAction('Rename');
        $this->key("\r");
        $this->promptOpens();
        $this->clearPrompt('feat');
        $this->type('no/such/parent');
        $this->key("\r");
        $this->pumpUntil();

        $this->assertStringContainsString('fatal:', $this->statusRow($this->statusBar));
        $this->assertDirectoryExists($this->at('feat'));
    }

    public function testWithNoOtherWorktreeThereIsNothingToRename(): void
    {
        $this->pickAction('Rename');

        $this->assertStringContainsString('No other worktrees to rename', $this->statusRow($this->statusBar));
    }

    // --- Locking --------------------------------------------------------------

    public function testLockingTakesAReasonAndUnlockingUndoesIt(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));

        $this->pickAction('Lock');
        $this->key("\r"); // feat
        $this->promptOpens();
        $this->type('on a usb disk');
        $this->key("\r");
        $this->pumpUntil();

        $this->assertStringContainsString('Locked feat', $this->statusRow($this->statusBar));
        $this->assertMatchesRegularExpression('/\x00locked on a usb disk(\x00|$)/', $this->listedRaw());

        $this->pickAction('Lock');
        $this->key("\r");
        $this->pumpUntil(fn (): bool => \str_contains($this->statusRow($this->statusBar), 'Unlocked'), what: 'the unlock');

        $this->assertStringNotContainsString('locked', $this->listedRaw());
    }

    public function testALockWithoutAReasonIsStillALock(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));

        $this->pickAction('Lock');
        $this->key("\r");
        $this->promptOpens();
        $this->key("\r");
        $this->pumpUntil();

        $this->assertMatchesRegularExpression('/\x00locked(\x00|$)/', $this->listedRaw(), 'locked, with no reason after it');
    }

    public function testOnlyALinkedWorktreeCanBeLocked(): void
    {
        $this->pickAction('Lock');

        $this->assertStringContainsString('No linked worktrees', $this->statusRow($this->statusBar));
    }

    public function testALockGitRefusesSaysWhy(): void
    {
        $this->project->git('worktree', 'add', '-q', '-b', 'feat', $this->at('feat'));
        $commands = $this->commands();
        $commands->worktrees();
        $this->overlayOpens();
        $this->type('Lock');
        $this->key("\r");
        $this->overlayOpens();
        // Locked behind the list's back: git refuses a second lock.
        $this->project->git('worktree', 'lock', $this->at('feat'));
        $this->key("\r");
        $this->promptOpens();
        $this->key("\r");
        $this->pumpUntil();

        $this->assertStringContainsString('fatal:', $this->statusRow($this->statusBar));
    }

    // --- Helpers --------------------------------------------------------------

    private function commands(?string $root = null): WorktreeCommands
    {
        $this->editor = new CodeEditorWidget();
        $this->statusBar = new StatusBarWidget();
        $tui = TestTui::create(120, 24);
        $root ??= $this->project->dir;

        return new WorktreeCommands(
            new GitInfo($root, gitPath: $this->gitPath),
            GitDoubles::modals($this->editor),
            GitDoubles::flash(new Notifier($this->statusBar, $tui)),
            $tui->requestRender(...),
            GitDoubles::translate(new Translator('en')),
            function (string $path, string $repeat): void {
                $this->opened[] = [$path, $repeat];
            },
            fn (): string => $this->setting,
            $root,
        );
    }

    /** Open the list and choose one of its actions, by the start of its name. */
    private function pickAction(string $action, ?string $root = null): void
    {
        $this->commands($root)->worktrees();
        $this->overlayOpens();
        $this->type($action);
        $this->key("\r");
        $this->pumpUntil(fn (): bool => EditorState::pickerOpen($this->editor) || '' !== \trim($this->statusRow($this->statusBar)), what: 'the second list, or why there is none');
    }

    /** A git that does $guard for some argument `$a`, and is the real git otherwise. */
    private function fakeGit(string $guard): string
    {
        $script = "#!/bin/sh\nfor a in \"\$@\"; do\n    {$guard}\ndone\nexec '".$this->whichGit()."' \"\$@\"\n";
        $this->fake = new TempProject(['git' => $script]);
        \chmod($this->fake->path('git'), permissions: 0o755);

        return $this->fake->path('git');
    }

    private ?TempProject $fake = null;

    private function at(string $name): string
    {
        return $this->folder->dir.'/'.$name;
    }

    private function gitIn(string $dir, string ...$args): string
    {
        $process = new Process(['git', '-C', $dir, ...$args]);
        $process->mustRun();

        return $process->getOutput();
    }

    /** @return list<string> the worktrees git knows, by path */
    private function listed(): array
    {
        return \array_values(\array_filter(\explode("\n", $this->project->git('worktree', 'list', '--porcelain')), static fn (string $l): bool => \str_starts_with($l, 'worktree ')));
    }

    private function listedRaw(): string
    {
        return $this->project->git('worktree', 'list', '--porcelain', '-z');
    }

    /** @return array{title: string, items: list<array{label: string, hint: string, value: string, disabled?: bool}>} */
    private function overlay(): array
    {
        /** @var array{title: string, items: list<array{label: string, hint: string, value: string, disabled?: bool}>}|null $overlay */
        $overlay = Probe::get(Probe::object($this->editor, 'overlayHost'), 'overlay');

        return $overlay ?? ['title' => '', 'items' => []];
    }

    /** @return array<string, array{label: string, hint: string, value: string, disabled?: bool}> the open list's rows by their label, unmarked */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->overlay()['items'] as $item) {
            $rows[\ltrim(\str_starts_with($item['label'], '✓ ') ? \substr($item['label'], \strlen('✓ ')) : $item['label'])] = $item;
        }

        return $rows;
    }

    private function clearPrompt(string $prefilled): void
    {
        foreach (\mb_str_split($prefilled) as $_) {
            $this->key("\x7f");
        }
    }
}
