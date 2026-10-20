<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\View;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\GitPlugin\View\GitMouseRouter;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Tests\Support\TestTui;
use Porta\Workbench\View\WheelAxis;
use Porta\Workbench\View\WheelGesture;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;

/**
 * The stages this view has no use for still have to answer.
 *
 * The pointer seam is defined by STAGE, not by verb, which is what let three
 * views share it — but it means every view implements every stage, including
 * the ones it does nothing with. Those are the branches no screen test walks.
 *
 * The settings view makes the same assertions in core's
 * `ViewPointerStagesTest`; this half moved here with the router, because a core
 * test may not name a plugin class.
 */
final class GitPointerStagesTest extends TestCase
{
    private function router(): GitMouseRouter
    {
        $translator = new Translator('en');
        $tui = TestTui::create(100, 24);

        return new GitMouseRouter(
            new HitTester(new Renderer(new StyleSheet())),
            GitDoubles::host($tui),
            new ChangesListWidget($translator),
            new DiffViewWidget($translator),
            new CommitBoxWidget($translator),
            new HistoryListWidget($translator),
            new GitTabsWidget($translator),
        );
    }

    private function mouse(): MouseEvent
    {
        $event = MouseEvent::parse("\x1b[<0;10;5M");
        $this->assertInstanceOf(MouseEvent::class, $event, 'the SGR sequence above is well-formed');

        return $event;
    }

    public function testANotchOrPressThatMissesEveryPaneIsRefused(): void
    {
        // Nothing is laid out here, so every hit-test misses — which is the
        // state these two stages report by returning false. The caller reads
        // that to decide whether the workspace beneath should get the event,
        // so a stage that swallowed a miss would eat clicks over the chrome.
        $router = $this->router();

        $this->assertFalse($router->wheel(new WheelGesture($this->mouse(), WheelAxis::Vertical, 3)));
        $this->assertFalse($router->press($this->mouse()));
    }

    public function testBothScrollAxesAreOfferedBecauseTheDiffPaneScrollsSideways(): void
    {
        // The axis arrives as data on the gesture rather than as a separate
        // verb, which is how this router's two scroll methods became one. The
        // diff pane is the reason the horizontal one exists at all — it is the
        // only pane in any view that scrolls that way.
        $router = $this->router();

        $this->assertFalse($router->wheel(new WheelGesture($this->mouse(), WheelAxis::Horizontal, 3)), 'nothing laid out, so it still misses');
    }
}
