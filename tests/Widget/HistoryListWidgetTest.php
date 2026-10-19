<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Command\GitRemoteOperation;
use Porta\GitPlugin\Event\GitRemoteRequested;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Tests\Support\GitWidgetEvents;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\PagesThroughRows;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The History tab's commit list, GitHub Desktop's way: each commit two rows,
 * its subject over who wrote it and how long ago.
 */
final class HistoryListWidgetTest extends TestCase
{
    use PagesThroughRows;
    use RendersWidget;

    private const int NOW = 1_700_000_000;

    /**
     * A page is the commits the pane SHOWS. Paging by its height in rows
     * moved twice as far as the screen showed, now that a commit takes two.
     */
    public function testPagingDownShowsEveryCommit(): void
    {
        $widget = new HistoryListWidget(new Translator('en'), clock: static fn (): int => self::NOW);
        $widget->setFocused(true);
        $commits = [];
        $labels = [];
        for ($i = 0; $i < 60; ++$i) {
            $subject = \sprintf('Commit %02d', $i);
            $labels[] = $subject;
            $commits[] = new LogEntry("full{$i}", \sprintf('sha%03d', $i), 'Alice', self::NOW - $i, $subject);
        }
        $widget->setCommits($commits);

        self::assertPagingShowsEveryRow(
            fn (): string => $this->plain($widget->render(new RenderContext(60, 20))),
            static fn () => $widget->handleInput("\x1b[6~"),
            $labels,
        );
    }

    private function widget(?EventDispatcher $events = null): HistoryListWidget
    {
        $widget = new HistoryListWidget(new Translator('en'), events: $events, clock: static fn (): int => self::NOW);
        $widget->setFocused(true);
        $widget->setCommits([
            new LogEntry('full1', 'aaa111', 'Alice', self::NOW - 7_200, 'Newest work'),
            new LogEntry('full2', 'bbb222', 'Bob', self::NOW - (3 * 86_400), 'Older work'),
        ]);

        return $widget;
    }

    /** @return list<string> */
    private static function rows(HistoryListWidget $widget, int $rows = 9): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(44, $rows)));
    }

    public function testEachCommitIsItsSubjectOverWhoAndWhen(): void
    {
        $rows = self::rows($this->widget());

        $this->assertCount(9, $rows, 'padded to the full height');
        $this->assertStringStartsWith(' History (2)', $rows[0]);
        $this->assertSame(['▌Newest work', '▌Alice · 2 hours ago', ' Older work', ' Bob · 3 days ago'], \array_map(\rtrim(...), \array_slice($rows, offset: 1, length: 4)));
        $styles = Styles::dark();
        $raw = $this->widget()->render(new RenderContext(44, 9));
        $this->assertStringContainsString($styles->of(Role::Emphasis)->withBold()->apply('Older work'), $raw[3], 'the subject bold');
        $this->assertStringContainsString($styles->of(Role::Dim)->apply('Bob · 3 days ago'), $raw[4], 'who and when dim');
    }

    /** The repository names its commits; a control byte in one is shown, never obeyed. */
    public function testASubjectsControlBytesAreNotPassedToTheTerminal(): void
    {
        $widget = new HistoryListWidget(new Translator('en'), clock: static fn (): int => self::NOW);
        $widget->setCommits([new LogEntry('s', 'aaa111', "Eve\x1b]52;c;x\x07", self::NOW, "Fix\x1b[2J it")]);

        $joined = \implode('', $widget->render(new RenderContext(44, 5)));

        $this->assertStringNotContainsString("\x1b[2J", $joined);
        $this->assertStringNotContainsString("\x1b]52", $joined);
    }

    /** A selected commit's two rows are both shaded, the subject keeping the selection's foreground. */
    public function testASelectedCommitShadesBothRows(): void
    {
        $widget = $this->widget();
        AttachedStyles::attach($widget);
        $lines = $widget->render(new RenderContext(44, 9));

        $this->assertStringContainsString("\x1b[48;5;31m", $lines[1], 'the focused selection uses the focus rule');
        $this->assertStringContainsString("\x1b[48;5;31m", $lines[2], 'on both of its rows');
        $this->assertStringNotContainsString("\x1b[48;5;31m", $lines[3]);

        $widget->setFocused(false);
        $lines = $widget->render(new RenderContext(44, 9));
        $this->assertStringContainsString("\x1b[48;5;237m", $lines[1], 'unfocused, the shared unfocused shade');
        $this->assertSame('▎', \mb_substr(AnsiUtils::stripAnsiCodes($lines[1]), start: 0, length: 1), 'and the quarter bar');
    }

    public function testNavigationFiresAndEnterMovesToTheFiles(): void
    {
        $bus = new EventDispatcher();
        $widget = $this->widget($bus);
        $events = [];
        GitWidgetEvents::wire($bus, onSelectCommit: static function (LogEntry $c) use (&$events): void {
            $events[] = 'commit:'.$c->shortSha;
        });
        $widget->onFocusNext(static function () use (&$events): void {
            $events[] = 'next';
        });

        $widget->handleInput("\x1b[B"); // second commit
        $widget->handleInput("\r"); // into its files, the column beside

        $this->assertSame(['commit:bbb222', 'next'], $events);
        $this->assertSame('full2', $widget->selectedCommit()?->sha);
    }

    public function testHoverTintsACommitsTwoRowsWithoutSelecting(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->render(new RenderContext(44, 9));
        $fired = 0;
        GitWidgetEvents::wire($events, onSelectCommit: static function () use (&$fired): void {
            ++$fired;
        });

        $this->assertTrue($widget->hoverRow(4), 'the second commit\'s second row');
        $this->assertFalse($widget->hoverRow(3), 'its first row is the same commit: no repaint');
        $rendered = $widget->render(new RenderContext(44, 9));
        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[3]);
        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[4]);
        $this->assertSame('full1', $widget->selectedCommit()?->sha, 'selection untouched');

        $this->assertTrue($widget->hoverRow(0), 'the title clears the tint');
        $this->assertFalse($widget->clearHover(), 'already clear');
        $this->assertFalse($widget->hoverRow(7), 'nor is there a commit past the last');
        $this->assertTrue($widget->hoverRow(1));
        $this->assertTrue($widget->clearHover());
        $this->assertSame(0, $fired, 'hover fires no selection');

        $widget->hoverRow(1);
        $widget->setCommits([new LogEntry('x', 'ccc333', 'Cara', self::NOW, 'Reset')]);
        $this->assertFalse($widget->clearHover(), 'setCommits already dropped the tint');
    }

    public function testAClickOnEitherRowSelectsTheCommitOnce(): void
    {
        $bus = new EventDispatcher();
        $widget = $this->widget($bus);
        $events = [];
        GitWidgetEvents::wire($bus, onSelectCommit: static function (LogEntry $c) use (&$events): void {
            $events[] = $c->shortSha;
        });
        $widget->render(new RenderContext(44, 9)); // teach it its height

        $widget->handleClick(4); // the second commit's second row
        $widget->handleClick(3); // its first row: already selected, no reload
        $widget->handleClick(0); // the title
        $widget->handleClick(8); // past the commits

        $this->assertSame(['bbb222'], $events);
    }

    /** A right-click selects the commit under it and hands it to the menu. */
    public function testARightClickSelectsTheCommitForItsMenu(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(44, 9));

        $this->assertSame('full2', $widget->selectCommitAt(3)?->sha);
        $this->assertSame(4, $widget->selectedCommitRow(), 'the menu opens under the entry\'s last row');
        $this->assertNull($widget->selectCommitAt(0), 'not on the title');
        $this->assertSame('full2', $widget->selectCommitAt(4)?->sha, 'the same commit again: still its entry');
    }

    public function testShowChangesHookAndHousekeeping(): void
    {
        $dispatcher = new EventDispatcher();
        $widget = $this->widget($dispatcher);
        $events = [];
        $dispatcher->addListener(GitViewModeRequested::class, static function () use (&$events): void {
            $events[] = 'changes';
        });
        $widget->onFocusNext(static function () use (&$events): void {
            $events[] = 'next';
        });
        $widget->onExit(static function () use (&$events): void {
            $events[] = 'exit';
        });
        $widget->onInput(static fn (string $d): bool => false);

        $hooked = new HistoryListWidget(new Translator('en'));
        $hooked->setFocused(true);
        $hooked->onInput(static fn (string $d): bool => true);
        $hooked->handleInput('1'); // swallowed by the hook

        $widget->handleInput('1');
        $widget->handleInput("\t");
        $widget->handleInput("\x1b");
        $this->assertSame(['changes', 'next', 'exit'], $events);

        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded());
        $widget->setCommits([]);
        $this->assertNull($widget->selectedCommit());
    }

    public function testLoadMoreFiresOnceAtTheBottomAndAppendPreservesSelection(): void
    {
        $events = new EventDispatcher();
        $widget = new HistoryListWidget(new Translator('en'), events: $events);
        $widget->setFocused(true);
        $widget->setCommits([
            new LogEntry('s1', 'aaa111', 'A', 1_700_000_300, 'one'),
            new LogEntry('s2', 'bbb222', 'B', 1_700_000_200, 'two'),
            new LogEntry('s3', 'ccc333', 'C', 1_700_000_100, 'three'),
        ]);
        $loads = 0;
        $widget->markHasMore();
        GitWidgetEvents::wire($events, onLoadMore: static function () use (&$loads): void {
            ++$loads;
        });

        $widget->handleInput("\x1b[B");
        $this->assertSame(0, $loads, 'mid-list navigation never loads');
        $widget->handleInput("\x1b[B"); // bottom row
        $this->assertSame(1, $loads);
        $widget->handleInput("\x1b[A");
        $widget->handleInput("\x1b[B"); // bottom again, latch still armed
        $this->assertSame(1, $loads, 'one in-flight load at a time');

        // The append lands: dedupe by sha, selection preserved, latch cleared.
        $widget->appendCommits([
            new LogEntry('s3', 'ccc333', 'C', 1_700_000_100, 'three'), // --skip drift duplicate
            new LogEntry('s4', 'ddd444', 'D', 1_700_000_000, 'four'),
        ]);
        $this->assertSame(4, $widget->commitCount());
        $this->assertSame('three', $widget->selectedCommit()?->subject, 'the selection survived the append');

        $widget->handleInput("\x1b[B"); // the new bottom re-arms
        $this->assertSame(2, $loads);

        // No older page: bottom navigation stays quiet.
        $widget->markNoMore();
        $widget->handleInput("\x1b[A");
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $this->assertSame(2, $loads);
    }

    public function testRemoteKeysRouteToTheHook(): void
    {
        $this->widget()->handleInput('f'); // no dispatcher attached: a safe no-op

        $dispatcher = new EventDispatcher();
        $widget = $this->widget($dispatcher);
        $operations = [];
        $dispatcher->addListener(GitRemoteRequested::class, static function (GitRemoteRequested $event) use (&$operations): void {
            $operations[] = $event->operation;
        });
        $widget->handleInput('p');
        $widget->handleInput('P');
        $this->assertSame([GitRemoteOperation::Pull, GitRemoteOperation::Push], $operations);
    }

    /** Either row of a commit says it in full, and to the minute. */
    public function testEitherRowOfACommitSaysItInFull(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(44, 9));

        $tip = $widget->tooltipAt(3, 5);
        $this->assertSame($tip?->text, $widget->tooltipAt(4, 5)?->text);
        $this->assertSame("full2\nOlder work\nBob · ".\date('Y-m-d H:i', self::NOW - (3 * 86_400)), $tip?->text);
        $this->assertNull($widget->tooltipAt(0, 5), 'the title says nothing');
    }

    /**
     * A side panel draws at its own width however wide the layout is, and a
     * dropdown over it composites at that width too — at the layout's, the row
     * padded out past the panel and starved the pane beside it.
     */
    public function testAnOverlayCompositesAtThePanelWidthNotTheLayouts(): void
    {
        $widget = new HistoryListWidget(new Translator('en'));
        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 200, 'segment' => \str_repeat('M', times: 200)]]);

        $row = $this->painted($widget, new RenderContext(300, 5))[0];
        $this->assertSame($widget->width(), AnsiUtils::visibleWidth($row));
        $this->assertStringContainsString('MMM', $row);
    }
}
