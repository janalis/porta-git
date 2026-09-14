<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\View\GitViewMode;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\I18n\Translator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class GitTabsWidgetTest extends TestCase
{
    public function testRendersBothTabsWithTheActiveOneHighlighted(): void
    {
        $widget = new GitTabsWidget(new Translator('en'));
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
        $widget = new GitTabsWidget(new Translator('en'), events: $events);
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
        $widget = new GitTabsWidget(new Translator('en'));
        $widget->setActive(GitViewMode::Changes); // already active: no-op

        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 8, 'segment' => "\x1b[7mDROPDOWN\x1b[0m"]]);
        $line = $widget->render(new RenderContext(44, 1))[0];
        $this->assertStringContainsString('DROPDOWN', $line, 'a dropdown span composites over the strip');
    }

    public function testHoverTintsTheInactiveTabOnly(): void
    {
        $widget = new GitTabsWidget(new Translator('en'));

        $this->assertTrue($widget->hoverMode(GitViewMode::History), 'hovering the inactive tab changes the tint');
        $this->assertFalse($widget->hoverMode(GitViewMode::History), 'same tab: no change');
        $line = $widget->render(new RenderContext(44, 1))[0];
        $this->assertStringContainsString("\x1b[48;5;240m", $line, 'the hovered tab sits on the bar-chrome hover shade');

        $this->assertTrue($widget->hoverMode(GitViewMode::Changes), 'hovering the ACTIVE tab records it');
        $this->assertStringNotContainsString("\x1b[48;5;240m", $widget->render(new RenderContext(44, 1))[0], 'but the active style wins');

        $this->assertTrue($widget->hoverMode(null), 'leaving the strip clears');
    }
}
