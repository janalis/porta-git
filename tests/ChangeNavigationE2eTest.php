<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\GitPlugin\ChangeNavigation;
use Porta\Ide;
use Porta\Input\GutterClickRouter;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Tui;

/**
 * A file's changes against HEAD walked and read from the editor: next and
 * previous change from the gutter's marks, one change shown with what it
 * removed and added, and reverted in the buffer.
 */
final class ChangeNavigationE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    public function testChangeStartsAreTheFirstLineOfEachRun(): void
    {
        $this->assertSame([1, 4, 9], ChangeNavigation::changeStarts([5 => 'added', 1 => 'modified', 4 => 'modified', 2 => 'modified', 9 => 'deleted']));
        $this->assertSame([], ChangeNavigation::changeStarts([]));
    }

    public function testTheChangesAreWalkedShownAndReverted(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}', 'a.txt' => "a\nb\nc\nd\ne\nf\n"]);
        $project->initGit();
        $project->commitAll('init');
        $project->write('a.txt', "a\nB\nc\nd\nE\nf\n");

        $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                400 => function (Tui $tui, $terminal, Ide $ide): void {
                    $editor = $tui->getById('editor');
                    $this->assertInstanceOf(CodeEditorWidget::class, $editor, 'the editor is the code editor');
                    $this->await(static fn (): bool => 2 === \count($editor->decorations->vcsMarks), what: 'the two changes to be marked');
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $line = static fn (): int => $editor->getDocument()->cursorLine;

                    $commands->run('git_next_change');
                    $this->assertSame(1, $line());
                    $commands->run('git_next_change');
                    $this->assertSame(4, $line());
                    $commands->run('git_next_change');
                    $this->assertSame(1, $line(), 'wrapping past the end');
                    $commands->run('git_prev_change');
                    $this->assertSame(4, $line(), 'and back past the start');
                    $commands->run('git_prev_change');
                    $this->assertSame(1, $line());

                    // A click on the change mark: the caret goes there and the change shows.
                    Services::resolve($ide, GutterClickRouter::class)->click(0, 4);
                    $this->assertSame(4, $line());
                    $this->await(static fn (): bool => EditorState::pickerOpen($editor), what: 'the change to show');
                    $items = Probe::get(Probe::object($editor, 'overlayHost'), 'overlay')['items'];
                    $this->assertSame(['− e', '+ E', 'Revert this change'], \array_column($items, 'label'));
                    $this->assertSame([true, false], \array_slice(\array_column($items, 'disabled'), offset: 0, length: 2), 'what it removed is greyed');

                    $editor->handleInput("\x1b[B");
                    $editor->handleInput("\x1b[B");
                    $editor->handleInput("\r");
                    $this->assertSame("a\nB\nc\nd\ne\nf\n", $editor->getDocument()->getText(), 'reverted in the buffer');
                    $this->assertContains('Change reverted', Toasts::all($ide));
                },
                700 => function (Tui $tui, $terminal, Ide $ide): void {
                    $editor = $tui->getById('editor');
                    $this->assertInstanceOf(CodeEditorWidget::class, $editor, 'the editor is the code editor');
                    $commands = Services::resolve($ide, CommandRegistry::class);

                    // The buffer moved on from the disk the hunks describe: no revert.
                    $editor->getDocument()->setCursor(1, 0);
                    $editor->handleInput('x');
                    $commands->run('git_show_change');
                    $this->await(static fn (): bool => EditorState::pickerOpen($editor), what: 'the change to show');
                    $editor->handleInput("\x1b[B");
                    $editor->handleInput("\x1b[B");
                    $editor->handleInput("\r");
                    $this->assertStringContainsString('xB', $editor->getDocument()->getText(), 'nothing replaced');
                    $this->assertContains('The buffer has changed since; save it and try again', Toasts::all($ide));

                    $editor->getDocument()->setCursor(2, 0);
                    $commands->run('git_show_change');
                    $this->await(static fn (): bool => \in_array('No change here', Toasts::all($ide), strict: true), what: 'the answer for an unchanged line');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    public function testACleanFileHasNoChangeToWalkTo(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}', 'a.txt' => "a\n"]);
        $project->initGit();
        $project->commitAll('init');

        $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                400 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('git_next_change');
                    $this->assertContains('No changes in this file', Toasts::all($ide));
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /** A pure deletion is marked on the line above it, and reverting puts the deleted line back under it. */
    public function testADeletionIsShownAndPutBack(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}', 'a.txt' => "a\nb\nc\n"]);
        $project->initGit();
        $project->commitAll('init');
        $project->write('a.txt', "a\nc\n");

        $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                400 => function (Tui $tui, $terminal, Ide $ide): void {
                    $editor = $tui->getById('editor');
                    $this->assertInstanceOf(CodeEditorWidget::class, $editor, 'the editor is the code editor');
                    $this->await(static fn (): bool => [] !== $editor->decorations->vcsMarks, what: 'the deletion to be marked');
                    $this->assertSame([0], \array_keys($editor->decorations->vcsMarks), 'on the line above it');

                    Services::resolve($ide, CommandRegistry::class)->run('git_show_change');
                    $this->await(static fn (): bool => EditorState::pickerOpen($editor), what: 'the change to show');
                    $this->assertSame(['− b', 'Revert this change'], \array_column(Probe::get(Probe::object($editor, 'overlayHost'), 'overlay')['items'], 'label'));
                    $editor->handleInput("\x1b[B");
                    $editor->handleInput("\r");
                    $this->assertSame("a\nb\nc\n", $editor->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /** With no file open there is nothing to walk or show, and nothing breaks. */
    public function testWithNoFileOpenTheVerbsDoNothing(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["git"]}', 'a.txt' => "a\n"]);
        $project->initGit();
        $project->commitAll('init');

        $this->runIde(
            $project,
            null,
            [
                400 => function (Tui $tui, $terminal, Ide $ide): void {
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $commands->run('git_next_change');
                    $commands->run('git_show_change');
                    $this->assertSame([], Toasts::all($ide), 'no file, no answer to give');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
