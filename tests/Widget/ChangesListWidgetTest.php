<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Command\GitRemoteOperation;
use Porta\GitPlugin\Event\GitRemoteRequested;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\Tests\Support\GitWidgetEvents;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\PagesThroughRows;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RendersWidget;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class ChangesListWidgetTest extends TestCase
{
    use PagesThroughRows;
    use RendersWidget;

    /** Minus the title row: paging by the pane height slid one file per press past the screen. */
    public function testPagingDownShowsEveryChangedFile(): void
    {
        $widget = new ChangesListWidget(new Translator('en'));
        $entries = [];
        $labels = [];
        for ($i = 0; $i < 40; ++$i) {
            $path = \sprintf('src/f%02d.php', $i);
            $labels[] = $path;
            $entries[] = new ChangeEntry($path, ' ', 'M');
        }
        $widget->setEntries($entries);

        self::assertPagingShowsEveryRow(
            fn (): string => $this->plain($widget->render(new RenderContext(44, 9))),
            static fn () => $widget->handleInput("\x1b[6~"),
            $labels,
        );
    }

    /** @return list<ChangeEntry> */
    private function entries(): array
    {
        return [
            new ChangeEntry('src/a.php', ' ', 'M'),
            new ChangeEntry('src/b.php', 'M', ' '),
            new ChangeEntry('c.txt', '?', '?'),
            new ChangeEntry('renamed.php', 'R', ' ', 'old.php'),
        ];
    }

    public function testRendersHeaderCheckboxesAndStatuses(): void
    {
        $widget = new ChangesListWidget(new Translator('en'));
        $widget->setEntries($this->entries());

        $screen = $this->plain($widget->render(new RenderContext(44, 8)));

        $this->assertStringContainsString('4 changed files', $screen);
        $this->assertStringContainsString('[ ] M src/a.php', $screen);
        $this->assertStringContainsString('[✓] M src/b.php', $screen);
        $this->assertStringContainsString('[ ] A c.txt', $screen, 'untracked shows as an unstaged addition');
        $this->assertStringContainsString('[✓] R renamed.php ← old.php', $screen);
    }

    public function testPartialStagingShowsTheMixedCheckbox(): void
    {
        $widget = new ChangesListWidget(new Translator('en'));
        $widget->setEntries([new ChangeEntry('x.php', 'M', 'M')]);

        $this->assertStringContainsString('[–] M x.php', $this->plain($widget->render(new RenderContext(44, 4))));
    }

    public function testNavigationFiresOnSelect(): void
    {
        $events = new EventDispatcher();
        $widget = new ChangesListWidget(new Translator('en'), events: $events);
        $widget->setFocused(true);
        $widget->setEntries($this->entries());
        $selected = [];
        GitWidgetEvents::wire($events, onSelect: static function (ChangeEntry $entry) use (&$selected): void {
            $selected[] = $entry->path;
        });

        $widget->handleInput("\x1b[B"); // down
        $widget->handleInput("\x1b[B");

        $this->assertSame(['src/b.php', 'c.txt'], $selected);
        $this->assertSame('c.txt', $widget->selectedEntry()?->path);
    }

    public function testSpaceTogglesStagingOnTheSelectedEntry(): void
    {
        $events = new EventDispatcher();
        $widget = new ChangesListWidget(new Translator('en'), events: $events);
        $widget->setFocused(true);
        $widget->setEntries($this->entries());
        $toggled = null;
        GitWidgetEvents::wire($events, onToggleStage: static function (ChangeEntry $entry) use (&$toggled): void {
            $toggled = $entry->path;
        });

        $widget->handleInput(' ');

        $this->assertSame('src/a.php', $toggled);
    }

    public function testDiscardNeedsInlineConfirmation(): void
    {
        $events = new EventDispatcher();
        $widget = new ChangesListWidget(new Translator('en'), events: $events);
        $widget->setFocused(true);
        $widget->setEntries($this->entries());
        $discarded = null;
        GitWidgetEvents::wire($events, onDiscard: static function (ChangeEntry $entry) use (&$discarded): void {
            $discarded = $entry->path;
        });

        $widget->handleInput('d');
        $this->assertStringContainsString('Discard changes?', $this->plain($widget->render(new RenderContext(44, 8))));

        $widget->handleInput('n'); // anything but y cancels
        $this->assertNull($discarded);
        $this->assertStringNotContainsString('Discard changes?', $this->plain($widget->render(new RenderContext(44, 8))));

        $widget->handleInput('d');
        $widget->handleInput('y');
        $this->assertSame('src/a.php', $discarded);
    }

    public function testArmedDiscardFollowsItsEntryWhenSetEntriesReorders(): void
    {
        $widget = new ChangesListWidget(new Translator('en'));
        $widget->setFocused(true);
        $widget->setEntries($this->entries()); // src/a.php at index 0
        $widget->handleInput('d'); // arm discard for src/a.php (index 0)

        // A background refresh reorders the list: src/a.php is now index 1.
        $widget->setEntries([
            new ChangeEntry('src/z.php', 'M', ' '),
            new ChangeEntry('src/a.php', ' ', 'M'),
        ]);

        $screen = $this->plain($widget->render(new RenderContext(44, 8)));
        $lines = \explode("\n", $screen);
        $this->assertStringContainsString('Discard changes?', $lines[2], 'the confirmation followed src/a.php to its new row');
        $this->assertStringNotContainsString('Discard changes?', $lines[1], 'not left behind on the old row');
    }

    public function testContextMenuTriggersReuseTheExistingCallbacks(): void
    {
        $events = new EventDispatcher();
        $widget = new ChangesListWidget(new Translator('en'), events: $events);
        $widget->setEntries($this->entries());
        $selected = [];
        $toggled = [];
        $history = 0;
        $events->addListener(GitViewModeRequested::class, static function (GitViewModeRequested $event) use (&$history): void {
            ++$history;
        });
        GitWidgetEvents::wire(
            $events,
            onSelect: static function (ChangeEntry $entry) use (&$selected): void {
                $selected[] = $entry->path;
            },
            onToggleStage: static function (ChangeEntry $entry) use (&$toggled): void {
                $toggled[] = $entry->path;
            },
        );
        $widget->render(new RenderContext(60, 8));

        $this->assertNull($widget->selectRowAt(0), 'the header row is no entry');
        $entry = $widget->selectRowAt(2); // row 2 = the second entry
        $this->assertSame('src/b.php', $entry?->path, 'a right-click selects the row under it');
        $this->assertSame(['src/b.php'], $selected);
        $this->assertSame(2, $widget->selectedRow(), 'the selected row maps back to its widget row');

        $widget->toggleStageSelected();
        $this->assertSame(['src/b.php'], $toggled, 'the Stage/Unstage item rides onToggleStage');

        $widget->showHistory();
        $this->assertSame(1, $history, 'the File History item dispatches the mode request');

        $widget->armDiscardSelected();
        $this->assertStringContainsString('Discard changes?', $this->plain($widget->render(new RenderContext(60, 8))), 'the Discard… item arms the two-press confirmation');
    }

    public function testSetEntriesKeepsSelectionByPath(): void
    {
        $widget = new ChangesListWidget(new Translator('en'));
        $widget->setFocused(true);
        $widget->setEntries($this->entries());
        $widget->handleInput("\x1b[B"); // select src/b.php

        // b.php became fully staged and the list shrank by one entry.
        $widget->setEntries([
            new ChangeEntry('src/b.php', 'M', ' '),
            new ChangeEntry('c.txt', '?', '?'),
        ]);

        $this->assertSame('src/b.php', $widget->selectedEntry()?->path);

        // The selected path vanished entirely: selection clamps.
        $widget->setEntries([new ChangeEntry('c.txt', '?', '?')]);
        $this->assertSame('c.txt', $widget->selectedEntry()?->path);
    }

    public function testClickTogglesInTheCheckboxColumnAndSelectsElsewhere(): void
    {
        $events = new EventDispatcher();
        $widget = new ChangesListWidget(new Translator('en'), events: $events);
        $widget->setEntries($this->entries());
        $toggled = null;
        $selected = null;
        GitWidgetEvents::wire(
            $events,
            onToggleStage: static function (ChangeEntry $entry) use (&$toggled): void {
                $toggled = $entry->path;
            },
            onSelect: static function (ChangeEntry $entry) use (&$selected): void {
                $selected = $entry->path;
            },
        );

        $widget->handleClick(2, 2); // row of src/b.php, inside [✓]
        $this->assertSame('src/b.php', $toggled);

        $widget->handleClick(3, 10); // row of c.txt, on the path
        $this->assertSame('c.txt', $selected);

        $widget->handleClick(0, 2); // header row: ignored
        $widget->handleClick(7, 2); // past the list: ignored
        $this->assertSame('src/b.php', $toggled);
    }

    public function testHoverTintsWithoutTouchingSelectionOrFiringSelect(): void
    {
        $events = new EventDispatcher();
        $widget = new ChangesListWidget(new Translator('en'), events: $events);
        $widget->setEntries($this->entries());
        $selectFired = 0;
        GitWidgetEvents::wire($events, onSelect: static function () use (&$selectFired): void {
            ++$selectFired;
        });

        $this->assertTrue($widget->hoverRow(2));
        $this->assertFalse($widget->hoverRow(2), 'same row: no repaint needed');
        $this->assertSame('src/a.php', $widget->selectedEntry()?->path, 'selection stays where it was');
        $this->assertSame(0, $selectFired, 'hover never loads a diff');

        $rendered = $widget->render(new RenderContext(44, 8));
        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[2], 'the hovered row is tinted');

        $this->assertTrue($widget->hoverRow(0), 'the header row clears the tint');
        $this->assertFalse($widget->clearHover(), 'already clear');
        $this->assertTrue($widget->hoverRow(3));
        $this->assertTrue($widget->clearHover(), 'pointer moved off the list');

        $widget->hoverRow(3);
        $widget->setEntries($this->entries());
        $rendered = $widget->render(new RenderContext(44, 8));
        $this->assertStringNotContainsString("\x1b[48;5;236m", \implode('', $rendered), 'setEntries drops a stale hover tint');
    }

    public function testInputHookConsumesFirstAndEntryCountCounts(): void
    {
        $widget = new ChangesListWidget(new Translator('en'));
        $widget->setFocused(true);
        $widget->setEntries($this->entries());
        $widget->onInput(static fn (string $data): bool => true);

        $widget->handleInput("\x1b[B"); // swallowed by the hook
        $this->assertSame('src/a.php', $widget->selectedEntry()?->path);
        $this->assertCount(4, Probe::get($widget, 'entries'));
    }

    public function testAlwaysFillsItsPane(): void
    {
        $widget = new ChangesListWidget(new Translator('en'));
        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded());
    }

    public function testTabAndEscapeFireTheirHooks(): void
    {
        $widget = new ChangesListWidget(new Translator('en'));
        $widget->setFocused(true);
        $widget->setEntries($this->entries());
        $events = [];
        $widget->onFocusNext(static function () use (&$events): void {
            $events[] = 'next';
        });
        $widget->onExit(static function () use (&$events): void {
            $events[] = 'exit';
        });

        $widget->handleInput("\t");
        $widget->handleInput("\x1b");

        $this->assertSame(['next', 'exit'], $events);
    }

    public function testRemoteKeysRouteToTheHook(): void
    {
        $bare = new ChangesListWidget(new Translator('en'));
        $bare->setFocused(true);
        $bare->handleInput('f'); // no dispatcher attached: a safe no-op

        $events = new EventDispatcher();
        $widget = new ChangesListWidget(new Translator('en'), events: $events);
        $widget->setFocused(true);
        $operations = [];
        $events->addListener(GitRemoteRequested::class, static function (GitRemoteRequested $event) use (&$operations): void {
            $operations[] = $event->operation;
        });
        $widget->handleInput('f');
        $widget->handleInput('p');
        $widget->handleInput('P');
        $this->assertSame([GitRemoteOperation::Fetch, GitRemoteOperation::Pull, GitRemoteOperation::Push], $operations);
    }

    /**
     * A side panel draws at its own width however wide the layout is, and a
     * dropdown over it composites at that width too — at the layout's, the row
     * padded out past the panel and starved the pane beside it.
     */
    public function testAnOverlayCompositesAtThePanelWidthNotTheLayouts(): void
    {
        $widget = new ChangesListWidget(new Translator('en'));
        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 200, 'segment' => \str_repeat('M', times: 200)]]);

        $row = $this->painted($widget, new RenderContext(300, 5))[0];
        $this->assertSame($widget->width(), AnsiUtils::visibleWidth($row));
        $this->assertStringContainsString('MMM', $row);
    }
}
