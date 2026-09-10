<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Diff\DiffDocument;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\RendersExactWidth;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * No git widget may render a row wider than the pane it was given.
 *
 * The plugin's half of {@see \Porta\Tests\Unit\Widget\NarrowPaneRenderTest},
 * and it was missing entirely: that sweep is a core test over core widgets, so
 * every widget this plugin mounts — the whole git view — had never been through
 * it. The crash it guards against is not hypothetical. `CodeEditorWidget` did
 * it twenty-one times in this repo's own `.porta/error.log`, and the first run
 * of this file found three more, all the same shape: a bound written with
 * `max(0, …)` or `max(1, …)` that clamps one PIECE of the row while the rest is
 * emitted at its natural width.
 *
 * - `HeaderRenderer::render()`'s key-hint suffix: the commit box's `Esc` came
 *   out four columns wide into a one-column pane.
 * - `DiffViewWidget::renderToolbar()`: the two display toggles are a fixed
 *   twenty-two columns and were drawn whatever the pane could seat.
 * - `DiffViewRenderer`'s row gutters, unified and split: eleven and five fixed
 *   columns with the TEXT clamped and the row unbounded.
 *
 * A `RenderException` does not garble a line — it escapes the render loop and
 * takes the session with it.
 */
final class NarrowGitPaneRenderTest extends TestCase
{
    use RendersExactWidth;

    private const string RAW = <<<'DIFF'
        diff --git a/src/demo.php b/src/demo.php
        --- a/src/demo.php
        +++ b/src/demo.php
        @@ -1,3 +1,4 @@
         context
        -$old = 2;
        +$new = 2;
        +$extra = 3;
        DIFF;

    /**
     * Every widget the git view mounts, each given something to DRAW — the
     * sweep refuses a widget that renders nothing, because a loop over no rows
     * asserts nothing while reporting success.
     *
     * @return iterable<string, array{AbstractWidget}>
     */
    public static function gitWidgets(): iterable
    {
        $changes = new ChangesListWidget(new Translator('en'));
        $changes->setEntries([
            new ChangeEntry('src/a.php', ' ', 'M'),
            new ChangeEntry('renamed.php', 'R', ' ', 'old.php'),
        ]);
        yield 'changes list' => [$changes];

        $history = new HistoryListWidget(new Translator('en'));
        $history->setFocused(true);
        $history->setCommits([new LogEntry('full1', 'aaa111', 'Alice', 1_700_000_100, 'Newest work')]);
        $history->setFiles([['status' => 'M', 'path' => 'src/a.php']]);
        yield 'history list' => [$history];

        yield 'git tabs' => [new GitTabsWidget(new Translator('en'))];

        // The commit box is the one that carries a key-hint suffix in its
        // header, which is the piece that overflowed.
        $box = new CommitBoxWidget(new Translator('en'));
        $box->setMessage('a commit message long enough to need the width');
        yield 'commit box' => [$box];

        // Three states, because they are three different assemblers: the
        // placeholder path draws only the toolbar, and split builds each row
        // from two halves rather than one gutter.
        $unified = new DiffViewWidget(new Translator('en'));
        $unified->setDocument(DiffDocument::parse(self::RAW), 'src/demo.php');
        yield 'diff (unified)' => [$unified];

        $split = new DiffViewWidget(new Translator('en'));
        $split->setDocument(DiffDocument::parse(self::RAW), 'src/demo.php');
        $split->useSplitView();
        yield 'diff (split)' => [$split];

        $empty = new DiffViewWidget(new Translator('en'));
        $empty->setDocument(null, null);
        yield 'diff (no selection)' => [$empty];
    }

    #[DataProvider('gitWidgets')]
    public function testAGitWidgetFitsEveryWidthDownToOne(AbstractWidget $widget): void
    {
        AttachedStyles::attach($widget);

        $this->assertFitsEveryWidth($widget);
    }
}
