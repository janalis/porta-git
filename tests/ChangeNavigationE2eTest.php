<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
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
use Porta\Tests\Support\Widgets;
use Symfony\Component\Tui\Tui;

/**
 * A file's changes against HEAD walked and read from the editor: core's next
 * and previous change over the marks this plugin paints — the join — and the
 * plugin's own view of one change, reverted in the buffer.
 */
final class ChangeNavigationE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use GitBinary;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    public function testTheChangesAreWalkedShownAndReverted(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["diff","git"]}', 'a.txt' => "a\nb\nc\nd\ne\nf\n"]);
        $project->initGit();
        $project->commitAll('init');
        $project->write('a.txt', "a\nB\nc\nd\nE\nf\n");

        $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                400 => function (Tui $tui, $terminal, Ide $ide): void {
                    $editor = Widgets::editor($tui);
                    $this->await(static fn (): bool => 2 === \count($editor->decorations->vcsMarks), what: 'the two changes to be marked');
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $line = static fn (): int => $editor->getDocument()->cursorLine;

                    $commands->run('next_change');
                    $this->assertSame(1, $line());
                    $commands->run('next_change');
                    $this->assertSame(4, $line());
                    $commands->run('next_change');
                    $this->assertSame(1, $line(), 'wrapping past the end');
                    $commands->run('prev_change');
                    $this->assertSame(4, $line(), 'and back past the start');
                    $commands->run('prev_change');
                    $this->assertSame(1, $line());

                    // Two lines typed above the changes: the marks move down
                    // with them, and so does the walk.
                    $editor->getDocument()->setCursor(0, 0);
                    $editor->handleInput("\r");
                    $editor->handleInput("\r");
                    $commands->run('next_change');
                    // The typed lines are changes themselves and join the
                    // first run; the next one is the second change, at 6.
                    $this->assertSame(6, $line(), 'the second change, two lines further down than on disk');
                    $editor->runAction('undo');
                    $editor->runAction('undo');
                    $this->await(static fn (): bool => "a\nB\nc\nd\nE\nf\n" === $editor->getDocument()->getText(), what: 'the typing undone');

                    // A click on the change mark: the caret goes there and the change shows.
                    Services::resolve($ide, GutterClickRouter::class)->click(0, 4);
                    $this->assertSame(4, $line());
                    $this->await(static fn (): bool => EditorState::pickerOpen($editor), what: 'the change to show');
                    $items = Probe::get(Probe::object($editor, 'overlayHost'), 'overlay')['items'];
                    $this->assertSame(['Revert this change', '− e', '+ E'], \array_column($items, 'label'), 'the verb first, then the change to read');
                    $this->assertSame([true, false], \array_column($items, 'disabled'), 'what it removed is greyed');
                    $this->assertSame(0, Probe::get(Probe::object($editor, 'overlayHost'), 'overlay')['selected'], 'the selection starts on the verb');

                    $editor->handleInput("\r");
                    $this->assertSame("a\nB\nc\nd\ne\nf\n", $editor->getDocument()->getText(), 'reverted in the buffer');
                    $this->assertContains('Change reverted', Toasts::all($ide));
                },
                700 => function (Tui $tui, $terminal, Ide $ide): void {
                    $editor = Widgets::editor($tui);
                    $commands = Services::resolve($ide, CommandRegistry::class);

                    // The buffer moved on from the disk the hunks describe: no revert.
                    $editor->getDocument()->setCursor(1, 0);
                    $editor->handleInput('x');
                    $commands->run('git_show_change');
                    $this->await(static fn (): bool => EditorState::pickerOpen($editor), what: 'the change to show');
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
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["diff","git"]}', 'a.txt' => "a\n"]);
        $project->initGit();
        $project->commitAll('init');

        $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                400 => function (Tui $tui, $terminal, Ide $ide): void {
                    Services::resolve($ide, CommandRegistry::class)->run('next_change');
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
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["diff","git"]}', 'a.txt' => "a\nb\nc\n"]);
        $project->initGit();
        $project->commitAll('init');
        $project->write('a.txt', "a\nc\n");

        $this->runIde(
            $project,
            $project->path('a.txt'),
            [
                400 => function (Tui $tui, $terminal, Ide $ide): void {
                    $editor = Widgets::editor($tui);
                    $this->await(static fn (): bool => [] !== $editor->decorations->vcsMarks, what: 'the deletion to be marked');
                    $this->assertSame([0], \array_keys($editor->decorations->vcsMarks), 'on the line above it');

                    Services::resolve($ide, CommandRegistry::class)->run('git_show_change');
                    $this->await(static fn (): bool => EditorState::pickerOpen($editor), what: 'the change to show');
                    $this->assertSame(['Revert this change', '− b'], \array_column(Probe::get(Probe::object($editor, 'overlayHost'), 'overlay')['items'], 'label'));
                    $editor->handleInput("\r");
                    $this->assertSame("a\nb\nc\n", $editor->getDocument()->getText());
                },
            ],
        );

        $this->addToAssertionCount(1);
    }

    /** With no file open there is no change to show, and nothing breaks. */
    public function testWithNoFileOpenTheVerbsDoNothing(): void
    {
        $this->skipWithoutGit();
        $project = new TempProject(['.porta.json' => '{"enabledPlugins":["diff","git"]}', 'a.txt' => "a\n"]);
        $project->initGit();
        $project->commitAll('init');

        $this->runIde(
            $project,
            null,
            [
                400 => function (Tui $tui, $terminal, Ide $ide): void {
                    $commands = Services::resolve($ide, CommandRegistry::class);
                    $commands->run('git_show_change');
                    $this->assertSame([], Toasts::all($ide), 'no file, no answer to give');
                },
            ],
        );

        $this->addToAssertionCount(1);
    }
}
