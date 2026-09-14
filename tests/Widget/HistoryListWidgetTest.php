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
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class HistoryListWidgetTest extends TestCase
{
    use PagesThroughRows;
    use RendersWidget;

    /**
     * The worst of the four. This pane gives the commit list only its middle:
     * a title, the commits, the Files header and eight file rows. Paging by the
     * pane height therefore moved TEN commits further than the screen showed,
     * so on a twenty-row pane a page skipped a whole page.
     */
    public function testPagingDownShowsEveryCommit(): void
    {
        $widget = new HistoryListWidget(new Translator('en'));
        $widget->setFocused(true);
        $commits = [];
        $labels = [];
        for ($i = 0; $i < 60; ++$i) {
            $subject = \sprintf('Commit %02d', $i);
            $labels[] = $subject;
            $commits[] = new LogEntry("full{$i}", \sprintf('sha%03d', $i), 'Alice', 1_700_000_000 - $i, $subject);
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
        $widget = new HistoryListWidget(new Translator('en'), events: $events);
        $widget->setFocused(true);
        $widget->setCommits([
            new LogEntry('full1', 'aaa111', 'Alice', 1_700_000_100, 'Newest work'),
            new LogEntry('full2', 'bbb222', 'Bob', 1_700_000_000, 'Older work'),
        ]);
        $widget->setFiles([
            ['status' => 'M', 'path' => 'src/a.php'],
            ['status' => 'A', 'path' => 'new.txt'],
        ]);

        return $widget;
    }

    private function rendered(HistoryListWidget $widget): string
    {
        return $this->plain($widget->render(new RenderContext(44, 16)));
    }

    public function testRendersCommitsAndFiles(): void
    {
        $screen = $this->rendered($this->widget());

        $this->assertStringContainsString('History (2)', $screen);
        // With 16 rows and 2 files, filler rows pad the files section.
        $this->assertGreaterThan(10, substr_count($screen, needle: "\n"), 'padded to full height');
        $this->assertStringContainsString('aaa111 Newest work  Alice', $screen);
        $this->assertStringContainsString('Files (2)', $screen);
        $this->assertStringContainsString('M src/a.php', $screen);
    }

    public function testCommitNavigationFiresAndEnterSwitchesToFiles(): void
    {
        $bus = new EventDispatcher();
        $widget = $this->widget($bus);
        $events = [];
        GitWidgetEvents::wire(
            $bus,
            onSelectCommit: static function (LogEntry $c) use (&$events): void {
                $events[] = 'commit:'.$c->shortSha;
            },
            onSelectFile: static function (LogEntry $c, string $path) use (&$events): void {
                $events[] = 'file:'.$path;
            },
        );

        $widget->handleInput("\x1b[B"); // second commit
        $widget->handleInput("\r"); // into the file list
        $widget->handleInput("\x1b[B"); // second file

        $this->assertSame(['commit:bbb222', 'file:new.txt'], $events);
        $this->assertSame('full2', $widget->selectedCommit()?->sha);
    }

    public function testEnterWithNoFilesLoadedStaysOnCommits(): void
    {
        $widget = new HistoryListWidget(new Translator('en'));
        $widget->setFocused(true);
        $widget->setCommits([
            new LogEntry('s1', 'aaa111', 'A', 1_700_000_100, 'one'),
            new LogEntry('s2', 'bbb222', 'B', 1_700_000_000, 'two'),
        ]);
        // No setFiles() call: the files list is empty.

        $widget->handleInput("\r"); // must not switch focus away from the commit list
        $widget->handleInput("\x1b[B"); // if it had switched, this would target the (empty) file list instead
        $this->assertSame('s2', $widget->selectedCommit()?->sha, 'the down-arrow still moved the commit selection');
    }

    public function testHoverTintsCommitAndFileRowsWithoutSelecting(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->render(new RenderContext(44, 16)); // commitRowCount = 6, Files header row 7
        $fired = 0;
        GitWidgetEvents::wire(
            $events,
            onSelectCommit: static function () use (&$fired): void {
                ++$fired;
            },
            onSelectFile: static function () use (&$fired): void {
                ++$fired;
            },
        );

        $this->assertTrue($widget->hoverRow(2), 'second commit row tints');
        $this->assertFalse($widget->hoverRow(2), 'same row: no repaint');
        $rendered = $widget->render(new RenderContext(44, 16));
        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[2]);
        $this->assertSame('full1', $widget->selectedCommit()?->sha, 'selection untouched');

        $this->assertTrue($widget->hoverRow(9), 'second file row tints');
        $rendered = $widget->render(new RenderContext(44, 16));
        $this->assertStringContainsString("\x1b[48;5;236m", $rendered[9]);
        $this->assertStringNotContainsString("\x1b[48;5;236m", $rendered[2], 'the commit tint moved away');

        $this->assertTrue($widget->hoverRow(7), 'the Files header clears both tints');
        $this->assertFalse($widget->clearHover(), 'already clear');
        $this->assertTrue($widget->hoverRow(1));
        $this->assertTrue($widget->clearHover());
        $this->assertSame(0, $fired, 'hover fires no selection callbacks');

        $widget->hoverRow(1);
        $widget->setCommits([new LogEntry('x', 'ccc333', 'Cara', 1_700_000_200, 'Reset')]);
        $this->assertFalse($widget->clearHover(), 'setCommits already dropped the tint');

        $widget->setFiles([['status' => 'M', 'path' => 'z.txt']]);
        $widget->render(new RenderContext(44, 16));
        $widget->hoverRow(8);
        $widget->setFiles([['status' => 'M', 'path' => 'z.txt']]);
        $this->assertFalse($widget->clearHover(), 'setFiles already dropped the file tint');
    }

    public function testClickRoutesToCommitOrFileRows(): void
    {
        $bus = new EventDispatcher();
        $widget = $this->widget($bus);
        $events = [];
        GitWidgetEvents::wire(
            $bus,
            onSelectCommit: static function (LogEntry $c) use (&$events): void {
                $events[] = 'commit:'.$c->shortSha;
            },
            onSelectFile: static function (LogEntry $c, string $path) use (&$events): void {
                $events[] = 'file:'.$path;
            },
        );
        $widget->render(new RenderContext(44, 16)); // teach it its height

        $widget->handleClick(2); // second commit row
        // With 16 rows: commits occupy rows 1..6, files header row 7, files from row 8.
        $widget->handleClick(9); // second file row

        $this->assertSame(['commit:bbb222', 'file:new.txt'], $events);
        $widget->handleClick(0); // header: ignored
        $this->assertCount(2, $events);
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
        $widget->setCommits([]); // reset clears files and focus
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

    public function testSelectionShadesFollowTheListElementRules(): void
    {
        // The focused sub-list resolves .list::selected:focus (bg 31); the
        // parked one shows the shared unfocused shade (bg 237) — visible in
        // every theme, including Monochrome's reverse video.
        $widget = $this->widget();
        AttachedStyles::attach($widget);

        $lines = $widget->render(new RenderContext(44, 16));
        $joined = implode("\n", $lines);
        $this->assertStringContainsString("\x1b[48;5;31m", $joined, 'the focused commit selection uses the focus rule');
        $this->assertStringContainsString("\x1b[48;5;237m", $joined, 'the parked files selection shows the unfocused shade');

        $widget->handleInput("\r"); // Enter → focus moves to the files sub-list
        $lines = $widget->render(new RenderContext(44, 16));
        $commitRow = AnsiUtils::stripAnsiCodes($lines[1]);
        $this->assertStringContainsString('aaa111', $commitRow, 'row 1 is the selected commit');
        $this->assertStringContainsString("\x1b[48;5;237m", $lines[1], 'the commit selection dims when the files own the focus');
    }

    public function testTheFilesSubHeaderUsesTheSectionHeaderConvention(): void
    {
        $widget = $this->widget();
        $lines = $widget->render(new RenderContext(44, 16));
        foreach ($lines as $line) {
            if (!str_contains(AnsiUtils::stripAnsiCodes($line), 'Files (2)')) {
                continue;
            }

            $this->assertStringContainsString("\x1b[48;5;235m", $line, 'in-list section headers sit on Toolbar');
            $this->assertStringContainsString("\x1b[1m", $line, 'and are bold');

            return;
        }
        $this->fail('the Files sub-header row was not rendered');
    }
}
