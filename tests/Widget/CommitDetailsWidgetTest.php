<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Command\GitRemoteOperation;
use Porta\GitPlugin\Event\GitCommitHashCopyRequested;
use Porta\GitPlugin\Event\GitRemoteRequested;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Tests\Support\GitWidgetEvents;
use Porta\GitPlugin\Widget\CommitDetailsWidget;
use Porta\GitPlugin\Widget\FileRow;
use Porta\GitPlugin\Widget\SplitShape;
use Porta\I18n\Translator;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\Rule;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * GitHub Desktop's middle column: the files the selected commit changed, each
 * driving the diff beside it — or over it, stacked in a narrow window.
 */
final class CommitDetailsWidgetTest extends TestCase
{
    use RendersWidget;

    private const string SHA = 'f00dfacecafebeef0123456789abcdef01234567';

    private EventDispatcher $bus;

    /** @var list<string> */
    private array $events = [];

    private function widget(string $subject = 'Record the parallel flake fixes'): CommitDetailsWidget
    {
        $this->bus = new EventDispatcher();
        $widget = new CommitDetailsWidget(new Translator('en'), Styles::dark(), $this->bus);
        GitWidgetEvents::wire($this->bus, onSelectFile: function (LogEntry $commit, string $path): void {
            $this->events[] = 'file:'.$path;
        });
        $this->bus->addListener(GitCommitHashCopyRequested::class, function (GitCommitHashCopyRequested $event): void {
            $this->events[] = 'copy:'.$event->commit->sha;
        });
        $widget->setFocused(true);
        $widget->setCommit(new LogEntry(self::SHA, 'f00dfac', 'phcorp', 1_700_000_000, $subject));
        $widget->setFiles([
            ['status' => 'M', 'path' => 'tasks/lessons.md'],
            ['status' => 'A', 'path' => 'tasks/todo.md'],
        ]);

        return $widget;
    }

    /** @return list<string> */
    private static function rows(CommitDetailsWidget $widget, int $width = 40, int $rows = 8): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext($width, $rows)));
    }

    public function testTheFilesAreCountedLevelWithTheDiffHeaderAndListedUnderARule(): void
    {
        $rows = self::rows($this->widget());

        $this->assertSame(
            [
                '            2 changed files             ',
                '────────────────────────────────────────',
                '▌tasks/lessons.md                     M ',
                ' tasks/todo.md                        A ',
                '                                        ',
                '                                        ',
                '                                        ',
                '                                        ',
            ],
            $rows,
        );
        $raw = $this->widget()->render(new RenderContext(40, 8));
        $styles = Styles::dark();
        $this->assertStringContainsString($styles->of(Role::Dim)->apply('tasks/'), $raw[2], 'the folder dim');
        $this->assertStringContainsString($styles->of(Role::Added)->apply('A'), $raw[3], 'the status in its tint');
        $this->assertSame(Rule::horizontal(40, $styles), $raw[1]);
    }

    /** Stacked over the diff, the column is its files alone — at most six — closed off by a rule. */
    public function testStackedTheListStopsAtSixRowsUnderNoHeading(): void
    {
        $widget = $this->widget();
        $widget->setFiles(\array_map(static fn (int $i): array => ['status' => 'M', 'path' => "f{$i}.php"], \range(1, end: 8)));
        $widget->stack(SplitShape::Stacked);

        $rows = self::rows($widget, width: 20, rows: 30);

        $this->assertCount(7, $rows, 'six files and the rule, however tall the room');
        $this->assertSame('▌f1.php           M ', $rows[0]);
        $this->assertSame('────────────────────', $rows[6]);
        $widget->handleClick(1);
        $this->assertSame('f2.php', $widget->selectedFile()['path'] ?? null, 'a press counts from the first row');
        $widget->handleInput("\x1b[6~"); // page down: six rows a page
        $this->assertSame('f8.php', $widget->selectedFile()['path'] ?? null);

        $widget->setFiles([['status' => 'A', 'path' => 'one.php']]);
        $this->assertCount(2, self::rows($widget, width: 20, rows: 30), 'one file, one row');
        $widget->stack(SplitShape::Beside);
        $this->assertCount(30, self::rows($widget, width: 20, rows: 30), 'beside the diff again, the column fills');
    }

    /** A narrow pane gives up the start of a file's folder first, never its name or status. */
    public function testANarrowPaneElidesTheFolderNotTheName(): void
    {
        $widget = $this->widget('Short');
        $widget->setFiles([['status' => 'M', 'path' => 'plugins/assist/src/Session/GuestSession.php']]);

        $row = self::rows($widget, width: 32)[2];

        $this->assertSame('▌…rc/Session/GuestSession.php M ', $row);
    }

    public function testNoCommitIsAnEmptyPane(): void
    {
        $widget = new CommitDetailsWidget(new Translator('en'));

        $rows = self::rows($widget, rows: 3);

        $this->assertSame(['                                        ', '────────────────────────────────────────', '                                        '], $rows);
        $this->assertNull($widget->commit());
        $this->assertNull($widget->selectedFile());
        $this->assertSame(0, $widget->fileCount());
    }

    public function testKeysMoveThroughTheFilesAndEnterGoesBack(): void
    {
        $widget = $this->widget();
        $back = 0;
        $widget->onBack(static function () use (&$back): void {
            ++$back;
        });
        $widget->render(new RenderContext(40, 8));

        $widget->handleInput("\x1b[B");
        $widget->handleInput("\r");

        $this->assertSame(['file:tasks/todo.md'], $this->events);
        $this->assertSame(1, $back);
        $this->assertSame('tasks/todo.md', $widget->selectedFile()['path'] ?? null);
    }

    public function testAClickSelectsAFileAndYCopiesTheSha(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(40, 8));

        $widget->handleClick(3); // the second file
        $widget->handleClick(1); // the rule: nothing
        $widget->handleClick(6); // past the files: nothing

        $this->assertSame(['file:tasks/todo.md'], $this->events);
        $widget->handleInput('y');
        $this->assertSame('copy:'.self::SHA, $this->events[1] ?? null, '`y` copies it');
    }

    public function testWithoutACommitThereIsNothingToCopy(): void
    {
        $widget = new CommitDetailsWidget(new Translator('en'), events: $bus = new EventDispatcher());
        $copied = 0;
        $bus->addListener(GitCommitHashCopyRequested::class, static function () use (&$copied): void {
            ++$copied;
        });

        $widget->handleInput('y');

        $this->assertSame(0, $copied);
    }

    public function testHoverTintsAFileRowOnly(): void
    {
        $widget = $this->widget();
        AttachedStyles::attach($widget);
        $widget->render(new RenderContext(40, 8));

        $this->assertFalse($widget->hoverRow(0), 'the heading is not a file');
        $this->assertTrue($widget->hoverRow(3));
        $this->assertFalse($widget->hoverRow(3), 'same row: no repaint');
        $this->assertStringContainsString("\x1b[48;5;236m", $widget->render(new RenderContext(40, 8))[3]);
        $this->assertTrue($widget->clearHover());
        $this->assertFalse($widget->clearHover(), 'already clear');
        $this->assertSame([], $this->events, 'hover selects nothing');
    }

    public function testACutPathExplainsItself(): void
    {
        $widget = $this->widget('Short');
        $widget->setFiles([['status' => 'M', 'path' => 'plugins/assist/src/Session/GuestSession.php'], ['status' => 'A', 'path' => 'a.md']]);
        $widget->render(new RenderContext(32, 8));

        $this->assertSame('plugins/assist/src/Session/GuestSession.php', $widget->tooltipAt(2, 3)?->text, 'a cut path says itself');
        $this->assertNull($widget->tooltipAt(3, 3), 'a whole one says nothing');
        $this->assertNull($widget->tooltipAt(0, 3), 'nor does the heading');
    }

    public function testThePaneKeysAndHousekeeping(): void
    {
        $widget = $this->widget();
        $seen = [];
        $this->bus->addListener(GitViewModeRequested::class, static function () use (&$seen): void {
            $seen[] = 'changes';
        });
        $this->bus->addListener(GitRemoteRequested::class, static function (GitRemoteRequested $event) use (&$seen): void {
            $seen[] = $event->operation;
        });
        $widget->onFocusNext(static function () use (&$seen): void {
            $seen[] = 'next';
        });
        $widget->onExit(static function () use (&$seen): void {
            $seen[] = 'exit';
        });

        $widget->handleInput('1');
        $widget->handleInput('f');
        $widget->handleInput("\t");
        $widget->handleInput("\x1b");
        $widget->handleInput('x'); // nothing it knows

        $this->assertSame(['changes', GitRemoteOperation::Fetch, 'next', 'exit'], $seen);
        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded());
        $widget->onInput(static fn (string $data): bool => true);
        $widget->handleInput("\x1b[B");
        $this->assertSame('tasks/lessons.md', $widget->selectedFile()['path'] ?? null, 'a hook that swallows the key');
    }

    public function testAnOverlayCompositesAtThePaneWidth(): void
    {
        $widget = $this->widget();
        $widget->setMenuOverlay([['row' => 0, 'col' => 0, 'width' => 10, 'segment' => \str_repeat('M', times: 10)]]);

        $this->assertStringStartsWith('MMMMMMMMMM', AnsiUtils::stripAnsiCodes($this->painted($widget, new RenderContext(40, 3))[0]));
    }

    /** A renamed file's old path is the repository's to name too: shown, never obeyed. */
    public function testARenamedFilesOldPathIsInert(): void
    {
        $row = FileRow::render('new.php', 'R', 40, Styles::dark(), "old\x1b]52;c;eA==\x07.php");

        $this->assertStringNotContainsString("\x1b]52", $row);
        $this->assertSame(40, AnsiUtils::visibleWidth($row));
    }
}
