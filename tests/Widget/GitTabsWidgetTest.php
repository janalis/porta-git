<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\View\GitViewMode;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\CompactScreen;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\ScreenDensity;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class GitTabsWidgetTest extends TestCase
{
    use RendersWidget;

    public function testRendersBothTabsWithTheActiveOneHighlighted(): void
    {
        $widget = new GitTabsWidget(new Translator('en'), density: CompactScreen::density());
        $lines = $widget->render(new RenderContext(44, 1));

        $this->assertCount(1, $lines);
        $plain = AnsiUtils::stripAnsiCodes($lines[0]);
        $this->assertStringContainsString('Changes', $plain);
        $this->assertStringContainsString('History', $plain);
        $this->assertStringContainsString("\x1b[48;5;238m", $lines[0], 'the active tab uses the shared strip family (TabActiveBg, bold)');
        $this->assertStringContainsString("\x1b[1m", $lines[0]);

        $widget->setActive(GitViewMode::History);
        $lines = $widget->render(new RenderContext(44, 1));
        $changesPos = \mb_strpos(AnsiUtils::stripAnsiCodes($lines[0]), needle: 'Changes');
        $highlightPos = \strpos($lines[0], needle: "\x1b[48;5;238m");
        $this->assertNotFalse($highlightPos);
        $this->assertStringContainsString('History', \substr($lines[0], $highlightPos), 'the highlight moved to History');
        $this->assertNotFalse($changesPos);
    }

    public function testClickOnTheInactiveTabFiresAndTheActiveOneDoesNot(): void
    {
        $selected = [];
        $events = new EventDispatcher();
        $widget = new GitTabsWidget(new Translator('en'), density: CompactScreen::density(), events: $events);
        $events->addListener(GitViewModeRequested::class, static function (GitViewModeRequested $event) use (&$selected): void {
            $selected[] = $event->mode;
        });
        $widget->render(new RenderContext(44, 1)); // populate the layout map

        $historyCol = GitViewMode::History === $widget->modeAtColumn(12) ? 12 : 15; // inside ' History '
        $this->assertSame(GitViewMode::History, $widget->modeAtColumn($historyCol));

        $widget->handleClick($historyCol);
        $this->assertSame([GitViewMode::History], $selected);

        // The controller flips the active tab; a repeat click is a no-op.
        $widget->setActive(GitViewMode::History);
        $widget->handleClick($historyCol);
        $this->assertSame([GitViewMode::History], $selected);

        // Clicking the (now inactive) Changes tab switches back.
        $widget->handleClick(2);
        $this->assertSame([GitViewMode::History, GitViewMode::Changes], $selected);

        // Dead space past the tabs selects nothing.
        $widget->handleClick(40);
        $this->assertNull($widget->modeAtColumn(40));
        $this->assertSame([GitViewMode::History, GitViewMode::Changes], $selected);
    }

    public function testSetActiveIsIdempotentAndOverlayComposites(): void
    {
        $widget = new GitTabsWidget(new Translator('en'), density: CompactScreen::density());
        $widget->setActive(GitViewMode::Changes); // already active: no-op

        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 8, 'segment' => "\x1b[7mDROPDOWN\x1b[0m"]]);
        $line = $this->painted($widget, new RenderContext(44, 1))[0];
        $this->assertStringContainsString('DROPDOWN', $line, 'a dropdown span composites over the strip');
    }

    public function testHoverTintsTheInactiveTabOnly(): void
    {
        $widget = new GitTabsWidget(new Translator('en'), density: CompactScreen::density());
        $widget->render(new RenderContext(44, 1)); // the pointer lands on what was drawn

        $this->assertTrue($widget->hoverMode(GitViewMode::History), 'hovering the inactive tab changes the tint');
        $this->assertFalse($widget->hoverMode(GitViewMode::History), 'same tab: no change');
        $line = $widget->render(new RenderContext(44, 1))[0];
        $this->assertStringContainsString("\x1b[48;5;240m", $line, 'the hovered tab sits on the bar-chrome hover shade');

        $this->assertTrue($widget->hoverMode(GitViewMode::Changes), 'hovering the ACTIVE tab records it');
        $this->assertStringNotContainsString("\x1b[48;5;240m", $widget->render(new RenderContext(44, 1))[0], 'but the active style wins');

        $this->assertTrue($widget->hoverMode(null), 'leaving the strip clears');
    }

    /**
     * A side panel draws at its own width however wide the layout is, and a
     * dropdown over it composites at that width too — at the layout's, the row
     * padded out past the panel and starved the pane beside it.
     */
    public function testAnOverlayCompositesAtThePanelWidthNotTheLayouts(): void
    {
        $widget = new GitTabsWidget(new Translator('en'));
        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 200, 'segment' => \str_repeat('M', times: 200)]]);

        $row = $this->painted($widget, new RenderContext(300, 5))[0];
        $this->assertSame($widget->width(), AnsiUtils::visibleWidth($row));
        $this->assertStringContainsString('MMM', $row);
    }

    /**
     * A tab the pane cut off is not a target. The strip used to lay out
     * every mode and truncate the line after, so a click past the edge —
     * or on the ellipsis — still switched to a tab nobody could see.
     */
    public function testATabThePaneCutOffCannotBeClicked(): void
    {
        $events = new EventDispatcher();
        $requested = [];
        $events->addListener(GitViewModeRequested::class, static function (GitViewModeRequested $event) use (&$requested): void {
            $requested[] = $event->mode;
        });
        $widget = new GitTabsWidget(new Translator('en'), density: CompactScreen::density(), events: $events);

        $line = AnsiUtils::stripAnsiCodes($widget->render(new RenderContext(14, 1))[0]);
        $this->assertStringNotContainsString('History', $line, 'too narrow for the second tab');
        for ($col = 0; $col < 14; ++$col) {
            $widget->handleClick($col);
        }

        $this->assertSame([], $requested);
    }

    /**
     * Folder tabs on a roomy screen, one row on a compact one — the
     * screen's density core hands the view, never the sidebar's height —
     * and the active mode's lid is heavy all round while the sidebar has the
     * keys.
     */
    public function testTheModeTabsArePillsOnARoomyScreen(): void
    {
        $density = new ScreenDensity();
        $widget = new GitTabsWidget(new Translator('en'), density: $density);
        $focused = true;
        $widget->setFocusProbe(static function () use (&$focused): bool {
            return $focused;
        });

        $lines = $widget->render(new RenderContext(44, 5));
        $tall = \array_map(AnsiUtils::stripAnsiCodes(...), $lines);
        $density->observe(29);
        $short = $widget->render(new RenderContext(44, 5));

        $this->assertCount(2, $tall, 'the labels and the baseline: the upper halves are the frame\'s, in the rule above');
        $this->assertStringStartsWith(' Changes  History ', $tall[0]);
        $this->assertSame(\str_repeat('▀', times: 9).\str_repeat('─', times: 35), $tall[1], 'the shown mode a pill, on a baseline that runs on beside it');
        $this->assertStringContainsString("\x1b[38;5;31m▀", $lines[1], 'blue while the sidebar has the keys');
        $this->assertCount(1, $short, 'the sidebar is as tall as before: the screen is not');

        $density->observe(30);
        $focused = false;
        $revision = $widget->getRenderRevision();
        $widget->beforeRender();
        $this->assertNotSame($revision, $widget->getRenderRevision(), 'a focus change repaints');
        $this->assertStringContainsString("\x1b[38;5;237m▀", $widget->render(new RenderContext(44, 5))[1], 'grey without them');
    }
}
