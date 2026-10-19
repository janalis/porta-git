<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\View;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Event\GitCommitFileSelected;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Tests\Support\GitDoubles;
use Porta\GitPlugin\View\GitMouseRouter;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\CommitDetailsWidget;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Layout\ViewRegions;
use Porta\Tests\Support\CompactScreen;
use Porta\Ui\Theme\Styles;
use Porta\Workbench\View\WheelAxis;
use Porta\Workbench\View\WheelGesture;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * The pointer over History's middle column, against real rects: a press on a
 * file selects it and takes the keys, the wheel walks the files, and the row
 * under the pointer tints.
 */
final class GitHistoryPointerTest extends TestCase
{
    private Tui $tui;

    private Renderer $renderer;

    private CommitDetailsWidget $details;

    private GitMouseRouter $router;

    /** @var list<string> */
    private array $selected = [];

    protected function setUp(): void
    {
        $translator = new Translator('en');
        $this->renderer = new Renderer(new StyleSheet());
        $this->tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), renderer: $this->renderer);
        $events = new EventDispatcher();
        $events->addListener(GitCommitFileSelected::class, function (GitCommitFileSelected $event): void {
            $this->selected[] = $event->path;
        });
        $history = new HistoryListWidget($translator);
        $tabs = new GitTabsWidget($translator);
        $diff = new DiffViewWidget($translator, density: CompactScreen::density());
        $diff->setStyle(new Style(flex: 2));
        $this->details = new CommitDetailsWidget($translator, events: $events);
        $this->details->setStyle(new Style(flex: 1));
        $this->details->setCommit(new LogEntry('full', 'abc1234', 'Alice', 1_700_000_000, 'Subject'));
        $this->details->setFiles([['status' => 'M', 'path' => 'a.php'], ['status' => 'A', 'path' => 'b.php'], ['status' => 'D', 'path' => 'c.php']]);

        // History's arrangement, as the controller makes it.
        $region = new ViewRegions(Styles::dark())->mint('git');
        $region->sidebar()->add($tabs)->add($history);
        $row = new ContainerWidget();
        $row->setStyle(new Style(direction: Direction::Horizontal, flex: 1));
        $row->expandVertically(true);
        $row->add($this->details)->add($diff);
        $region->content()->add($row);
        $region->area()->add($region->sidebar())->add($region->content());
        $this->tui->add($region->area());
        $this->tui->requestRender();
        $this->tui->processRender();

        $this->router = new GitMouseRouter(
            new HitTester($this->renderer),
            GitDoubles::host($this->tui),
            new ChangesListWidget($translator),
            $diff,
            new CommitBoxWidget($translator),
            $history,
            $tabs,
            details: $this->details,
        );
    }

    /** A left press on the details pane's widget-relative ($row, $col). */
    private function onDetails(int $row, int $col = 2): MouseEvent
    {
        $rect = $this->renderer->getWidgetRect($this->details);
        $this->assertInstanceOf(WidgetRect::class, $rect, 'the details pane was laid out');
        $event = MouseEvent::parse("\x1b[<0;".($rect->col + $col + 1).';'.($rect->row + $row + 1).'M');
        $this->assertInstanceOf(MouseEvent::class, $event);

        return $event;
    }

    public function testAPressOnAFileSelectsItAndTakesTheKeys(): void
    {
        // Rows: subject, author, the rule, then the files.
        $this->assertTrue($this->router->press($this->onDetails(4)));

        $this->assertSame(['b.php'], $this->selected);
        $this->assertSame($this->details, $this->tui->getFocus());
    }

    public function testTheWheelWalksTheFilesAndHoverTintsOne(): void
    {
        $this->assertTrue($this->router->wheel(new WheelGesture($this->onDetails(4), WheelAxis::Vertical, 2)));
        $this->assertSame(['b.php', 'c.php'], $this->selected, 'the wheel is the arrow keys');

        $this->router->hover($this->onDetails(3));
        $this->assertFalse($this->details->hoverRow(3), 'the router already tinted that row');
    }
}
