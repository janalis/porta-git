<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\View;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\GitPlugin\View\GitMouseRouter;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Layout\ViewRegions;
use Porta\Tests\Support\Probe;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * {@see GitMouseRouter} against REAL widget rects (unlike GitPointerStagesTest,
 * which only covers the "nothing laid out" miss branches). Drives the diff
 * pane's row-range drag lifecycle — the one piece of logic in this router with
 * a real boundary condition (a plain click's release must not arm an anchor).
 */
final class GitMouseRouterInteractionTest extends TestCase
{
    private DiffViewWidget $diff;
    private Tui $tui;
    private GitMouseRouter $router;

    protected function setUp(): void
    {
        $translator = new Translator('en');
        $renderer = new Renderer(new StyleSheet());
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), renderer: $renderer);
        $changes = new ChangesListWidget($translator);
        $this->diff = new DiffViewWidget($translator);
        $commitBox = new CommitBoxWidget($translator);
        $history = new HistoryListWidget($translator);
        $tabs = new GitTabsWidget($translator);

        $regions = new ViewRegions(Styles::dark());
        $region = $regions->mint('git');
        $sidebar = $region->sidebar();
        $sidebar->add($tabs)->add($changes)->add($commitBox);
        $region->area()->add($sidebar)->add($region->content());
        $region->content()->add($this->diff);

        $this->tui->add($region->area());
        $this->tui->requestRender();
        $this->tui->processRender();

        $this->router = new GitMouseRouter(
            new HitTester($renderer),
            GitDoubles::host($this->tui),
            $changes,
            $this->diff,
            $commitBox,
            $history,
            $tabs,
            $region,
        );
    }

    /** A left press at 0-based ($col, $row). */
    private function pressAt(int $col, int $row): MouseEvent
    {
        return $this->sgrPress(code: 0, col: $col, row: $row);
    }

    /** A left drag (motion bit set) at 0-based ($col, $row). */
    private function dragAt(int $col, int $row): MouseEvent
    {
        return $this->sgrPress(code: 32, col: $col, row: $row);
    }

    /** A left release at 0-based ($col, $row). */
    private function releaseAt(int $col, int $row): MouseEvent
    {
        $event = MouseEvent::parse("\x1b[<0;".($col + 1).';'.($row + 1).'m');
        $this->assertInstanceOf(MouseEvent::class, $event, 'the SGR sequence above is well-formed');

        return $event;
    }

    private function sgrPress(int $code, int $col, int $row): MouseEvent
    {
        $event = MouseEvent::parse("\x1b[<{$code};".($col + 1).';'.($row + 1).'M');
        $this->assertInstanceOf(MouseEvent::class, $event, 'the SGR sequence above is well-formed');

        return $event;
    }

    public function testPressingTheDiffBodyArmsTheDragSelectionAndFocusesTheDiffPane(): void
    {
        // The diff pane's rect starts at col 44 per the sidebar/content split;
        // row 1 is inside the body (row 0 is the toolbar/header row).
        $this->assertTrue($this->router->press($this->pressAt(col: 50, row: 1)));
        $this->assertSame($this->diff, $this->tui->getFocus(), 'the pressed pane took focus');

        // Armed: a follow-up drag must actually extend the selection.
        Probe::set($this->tui, 'renderRequested', false);
        $this->assertTrue($this->router->continueDrag($this->dragAt(col: 52, row: 3)));
        $this->assertTrue(Probe::get($this->tui, 'renderRequested'), 'the extended selection requested a redraw');
    }

    public function testPressingTheDiffHeaderRowDoesNotArmTheDragSelection(): void
    {
        // Row 0 (relative) is the header — the guard `$rel['row'] > 0` in
        // press() must exclude it, or a header click would start a drag.
        $this->assertTrue($this->router->press($this->pressAt(col: 50, row: 0)));

        // Unarmed: a subsequent drag motion must be refused outright (returns
        // false before even hit-testing), not silently treated as a no-op hit.
        $this->assertFalse($this->router->continueDrag($this->dragAt(col: 52, row: 3)), 'a header-row press never arms the drag');
    }

    public function testReleaseDisarmsTheDragSoALaterDragEventIsRefused(): void
    {
        $this->router->press($this->pressAt(col: 50, row: 1)); // arm
        $this->router->continueDrag($this->dragAt(col: 52, row: 3)); // extend, still armed
        $this->assertTrue($this->router->continueDrag($this->releaseAt(col: 52, row: 3)), 'the release itself is still handled (disarms)');

        // Now disarmed: the very next drag motion must be refused.
        $this->assertFalse($this->router->continueDrag($this->dragAt(col: 60, row: 5)), 'a drag after release must not resume the old selection');
    }
}
