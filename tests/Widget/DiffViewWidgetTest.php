<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Diff\DiffDocument;
use Porta\GitPlugin\Tests\Support\GitWidgetEvents;
use Porta\GitPlugin\Widget\DiffViewWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\Probe;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class DiffViewWidgetTest extends TestCase
{
    private const string RAW = <<<'DIFF'
        diff --git a/src/demo.php b/src/demo.php
        --- a/src/demo.php
        +++ b/src/demo.php
        @@ -3,3 +3,4 @@ function demo()
         $keep = 1;
        -$old = 2;
        +$new = 2;
        +$extra = 3;
        @@ -10,1 +11,1 @@
        -tail
        +tail!
        DIFF;

    private function widget(?EventDispatcher $events = null): DiffViewWidget
    {
        $widget = new DiffViewWidget(new Translator('en'), events: $events);
        $widget->setDocument(DiffDocument::parse(self::RAW), 'src/demo.php');

        return $widget;
    }

    /** @return list<string> */
    private function plainLines(DiffViewWidget $widget, int $cols = 80, int $rows = 12): array
    {
        return \array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext($cols, $rows)));
    }

    public function testRendersGuttersHunkHeadersAndBodies(): void
    {
        $lines = $this->plainLines($this->widget());

        $this->assertStringContainsString('src/demo.php', $lines[0]);
        $this->assertStringContainsString('@@ -3,3 +3,4 @@ function demo()', $lines[1]);
        $this->assertMatchesRegularExpression('/^\s+3\s+3 │ \$keep = 1;/', $lines[2], 'context: both line numbers');
        $this->assertMatchesRegularExpression('/^\s+4\s+ │-\$old = 2;/', $lines[3], 'deletion: old side only');
        $this->assertMatchesRegularExpression('/^\s+4 │\+\$new = 2;/', $lines[4], 'addition: new side only');
        $this->assertStringContainsString('@@ -10,1 +11,1 @@', $lines[6]);
    }

    public function testColorsMarkRowKinds(): void
    {
        $raw = $this->widget()->render(new RenderContext(80, 12));

        $this->assertStringContainsString("\x1b[38;5;75m", $raw[1], 'hunk header cyan');
        $this->assertStringContainsString("\x1b[38;5;203m", $raw[3], 'deletion red');
        $this->assertStringContainsString("\x1b[38;5;114m", $raw[4], 'addition green');
    }

    public function testAddedAndContextLinesKeepSyntaxHighlighting(): void
    {
        $raw = $this->widget()->render(new RenderContext(80, 12));

        // tempest/highlight colors PHP variables; the raw added row must carry
        // SGR codes beyond the plain green marker (i.e. highlighted body).
        $addRow = AnsiUtils::stripAnsiCodes($raw[4]);
        $this->assertStringContainsString('$new = 2;', $addRow);
        $this->assertGreaterThan(
            \substr_count($raw[3], needle: "\x1b["),
            \substr_count($raw[4], needle: "\x1b["),
            'the addition carries syntax SGR spans, the deletion stays plain',
        );
    }

    public function testPlaceholderWithoutADocument(): void
    {
        $widget = new DiffViewWidget(new Translator('en'));
        $widget->setDocument(null, null, 'Select a file');

        $lines = $this->plainLines($widget, 40, 5);
        $this->assertStringContainsString('Select a file', $lines[1]);
        $this->assertCount(5, $lines, 'pads to the full height');
    }

    public function testCursorMovementScrollFollowsAndClamps(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $this->plainLines($widget, 80, 4); // a first frame teaches the widget its viewport height

        // Three rows visible; moving the cursor past the window scrolls it.
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $lines = $this->plainLines($widget, 80, 4);
        $this->assertStringContainsString('[scrolled +1]', $lines[0]);

        $widget->handleInput("\x1b[6~"); // page down clamps at the last row
        $widget->handleInput("\x1b[6~");
        $widget->handleInput("\x1b[6~");
        $this->assertStringContainsString('tail!', \implode("\n", $this->plainLines($widget, 80, 4)));

        for ($i = 0; $i < 5; ++$i) {
            $widget->handleInput("\x1b[5~"); // page up back past the top clamps
        }
        $lines = $this->plainLines($widget, 80, 4);
        $this->assertStringContainsString('@@ -3,3', $lines[1], 'clamped back to the top');
        $this->assertStringNotContainsString('[scrolled', $lines[0]);
    }

    public function testSpaceOnAHunkHeaderSelectsTheWholeHunk(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->setFocused(true);
        $captured = null;
        GitWidgetEvents::wire($events, onStageSelection: static function (array $selected) use (&$captured): void {
            $captured = $selected;
        });
        $widget->handleInput(' '); // cursor starts on hunk 0's header

        $this->assertSame([0 => [0 => true, 1 => true, 2 => true, 3 => true]], $captured);
    }

    public function testSpaceOnALineSelectsJustThatLine(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->setFocused(true);
        $captured = null;
        GitWidgetEvents::wire($events, onStageSelection: static function (array $selected) use (&$captured): void {
            $captured = $selected;
        });
        $widget->handleInput("\x1b[B"); // rows: header, ctx, -old, +new...
        $widget->handleInput("\x1b[B");
        $widget->handleInput(' '); // on '-$old = 2;' (hunk 0, line 1)

        $this->assertSame([0 => [1 => true]], $captured);
    }

    public function testRangeAnchorSelectsTheSpanAndClears(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->setFocused(true);
        $captured = null;
        GitWidgetEvents::wire($events, onStageSelection: static function (array $selected) use (&$captured): void {
            $captured = $selected;
        });
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B"); // on the deletion row
        $widget->handleInput('v'); // arm the anchor
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B"); // down to '+extra'
        $widget->handleInput(' ');

        $this->assertSame([0 => [1 => true, 2 => true, 3 => true]], $captured, 'the ± rows in the range');

        // The anchor cleared: the next space is single-line again.
        $widget->handleInput(' ');
        $this->assertSame([0 => [3 => true]], $captured);
    }

    public function testCursorUpAndEmptyDocumentMovesAreSafe(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->setFocused(true);
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[A"); // back to the top
        $captured = null;
        GitWidgetEvents::wire($events, onStageSelection: static function (array $selected) use (&$captured): void {
            $captured = $selected;
        });
        $widget->handleInput(' ');
        $this->assertArrayHasKey(0, (array) $captured, 'cursor is back on hunk 0');

        $empty = new DiffViewWidget(new Translator('en'), events: $events);
        $empty->setDocument(null, null);
        $empty->setFocused(true);
        $empty->handleInput("\x1b[B"); // no rows: consumed, no crash
        $empty->handleInput(' ');
        $this->assertTrue(true);
    }

    public function testArmedRangeHighlightsRowsIncludingReversedAnchors(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $this->plainLines($widget, 80, 12);
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $widget->handleInput('v'); // anchor at row 2
        $widget->handleInput("\x1b[A"); // cursor row 1: reversed range [1..2]
        $raw = $widget->render(new RenderContext(80, 12));
        $this->assertStringContainsString("\x1b[48;5;236m", $raw[3], 'the anchor row (below the cursor) is range-highlighted');
    }

    public function testCycleContextKeyFiresTheHookExceptWhenReadOnlyOrEmpty(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->setFocused(true);
        $fired = 0;
        GitWidgetEvents::wire($events, onCycleContext: static function () use (&$fired): void {
            ++$fired;
        });

        $widget->handleInput('c');
        $this->assertSame(1, $fired, 'a normal diff cycles its context');

        $widget->makeReadOnly();
        $widget->handleInput('c');
        $this->assertSame(1, $fired, 'read-only history diffs cannot change context');

        $widget->makeEditable();
        $empty = new DiffViewWidget(new Translator('en'), events: $events);
        $empty->setDocument(null, null);
        $empty->setFocused(true);
        $empty->handleInput('c');
        $this->assertSame(1, $fired, 'no document, no rows: nothing to cycle');
    }

    public function testScrollByMovesAndClampsInBothDirections(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $this->plainLines($widget, 80, 4); // teaches the widget lastRows = 4

        $widget->scrollBy(1);
        $lines = $this->plainLines($widget, 80, 4);
        $this->assertStringContainsString('[scrolled +1]', $lines[0]);

        $widget->scrollBy(-5); // clamps at the top
        $lines = $this->plainLines($widget, 80, 4);
        $this->assertStringNotContainsString('[scrolled', $lines[0]);

        $widget->scrollBy(50); // clamps at the bottom
        $this->assertStringContainsString('tail!', \implode("\n", $this->plainLines($widget, 80, 4)));

        $empty = new DiffViewWidget(new Translator('en'));
        $empty->setDocument(null, null);
        $empty->scrollBy(1); // no rows: a safe no-op
        $this->assertFalse($empty->isSplit());
    }

    public function testReadOnlyIgnoresStagingKeys(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->setFocused(true);
        $widget->makeReadOnly();
        $fired = false;
        GitWidgetEvents::wire($events, onStageSelection: static function () use (&$fired): void {
            $fired = true;
        });
        $widget->handleInput('v');
        $widget->handleInput(' ');
        $this->assertFalse($fired);
    }

    public function testSamePathReloadKeepsTheCursor(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->setFocused(true);
        $this->plainLines($widget, 80, 12);
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B"); // cursor row 3

        // Reload the SAME path with a shorter diff: cursor clamps, not resets.
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-a\n+b\n"), 'src/demo.php');
        $captured = null;
        GitWidgetEvents::wire($events, onStageSelection: static function (array $selected) use (&$captured): void {
            $captured = $selected;
        });
        $widget->handleInput(' ');
        $this->assertSame([0 => [1 => true]], $captured, 'cursor clamped to the last row (+b)');

        // A DIFFERENT path resets to the top.
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-a\n+b\n"), 'other.php');
        $widget->handleInput(' ');
        $this->assertSame([0 => [0 => true, 1 => true]], $captured, 'cursor back on the hunk header');
    }

    public function testTabAndEscapeFireTheirHooks(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
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

    public function testInputHookConsumesFirst(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $this->plainLines($widget, 80, 4);
        $widget->onInput(static fn (string $data): bool => true);

        $widget->handleInput("\x1b[B"); // swallowed
        $this->assertStringNotContainsString('[scrolled', $this->plainLines($widget, 80, 4)[0]);
    }

    public function testReadOnlyFlagRoundTrips(): void
    {
        $widget = $this->widget();
        $this->assertFalse(Probe::get($widget, 'readOnly'));
        $widget->makeReadOnly();
        $this->assertTrue(Probe::get($widget, 'readOnly'));
        $this->assertTrue($widget->expandVertically(false)->isVerticallyExpanded(), 'always fills its pane');
    }

    public function testNoNewlineMarkerRendersDim(): void
    {
        $widget = new DiffViewWidget(new Translator('en'));
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-old\n\\ No newline at end of file\n+new\n"), 'x.txt');

        $lines = $this->plainLines($widget, 60, 6);
        $this->assertStringContainsString('No newline at end of file', $lines[3]);

        // In split mode the marker rides the old side of its own row.
        $widget->useSplitView();
        $lines = $this->plainLines($widget, 60, 6);
        $this->assertMatchesRegularExpression('/No newline.*│/', $lines[3], 'the marker rides the old side (truncated to the half width)');
    }

    public function testSplitModeRendersPairsSideBySide(): void
    {
        $widget = $this->widget();
        $widget->setFocused(true);
        $widget->handleInput('s');
        $this->assertTrue($widget->isSplit());

        $lines = $this->plainLines($widget);
        // The paired change sits on ONE row: old text left, new text right.
        $this->assertMatchesRegularExpression('/^\s+4 -\$old = 2;\s*│\s+4 \+\$new = 2;/', $lines[3]);
        // The surplus addition leaves the old side blank.
        $this->assertMatchesRegularExpression('/^\s{39}│\s+5 \+\$extra = 3;/', $lines[4]);
        // Context shows the same text on both sides.
        $this->assertMatchesRegularExpression('/\$keep = 1;.*│.*\$keep = 1;/', $lines[2]);

        $widget->handleInput('s'); // back to unified
        $this->assertFalse($widget->isSplit());
        $this->assertMatchesRegularExpression('/^\s+4\s+ │-\$old = 2;/', $this->plainLines($widget)[3]);
    }

    public function testSplitStagingSelectsThePairAndRangesUnionBothSides(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->setFocused(true);
        $widget->handleInput('s');
        $captured = null;
        GitWidgetEvents::wire($events, onStageSelection: static function (array $selected) use (&$captured): void {
            $captured = $selected;
        });
        // Split rows: header, ctx pair, (-old|+new), (blank|+extra), header, pair.
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B");
        $widget->handleInput(' '); // the pair row stages BOTH sides
        $this->assertSame([0 => [1 => true, 2 => true]], $captured);

        $widget->handleInput('v');
        $widget->handleInput("\x1b[B"); // include the surplus addition row
        $widget->handleInput(' ');
        $this->assertSame([0 => [1 => true, 2 => true, 3 => true]], $captured, 'the range unions every side in it');
    }

    public function testToolbarTagTogglesByClickAndFiresThePersistenceHook(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $toggles = [];
        GitWidgetEvents::wire($events, onLayoutToggle: static function () use (&$toggles, $widget): void {
            $toggles[] = $widget->isSplit();
        });
        $raw = $widget->render(new RenderContext(80, 12));
        $this->assertStringNotContainsString("\x1b[48;5;31m", $raw[0], 'inactive tag renders dim');

        // At 80 cols the tags start at col 58: ' S split ' spans 58-65.
        $widget->handleClick(0, 60);
        $this->assertTrue($widget->isSplit());
        $this->assertSame([true], $toggles);
        $raw = $widget->render(new RenderContext(80, 12));
        $this->assertStringContainsString("\x1b[48;5;31m", $raw[0], 'active tag renders selected');

        $widget->handleClick(0, 5); // title text: not a button
        $this->assertTrue($widget->isSplit());

        $widget->setFocused(true);
        $widget->handleInput('s'); // keyboard path fires the hook too
        $this->assertSame([true, false], $toggles);
    }

    public function testBodyClickMovesTheCursor(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->setFocused(true);
        $this->plainLines($widget); // teach the viewport
        $captured = null;
        GitWidgetEvents::wire($events, onStageSelection: static function (array $selected) use (&$captured): void {
            $captured = $selected;
        });
        $widget->handleClick(3, 10); // row 3 = the deletion (hunk 0 line 1)
        $widget->handleInput(' ');
        $this->assertSame([0 => [1 => true]], $captured);

        $widget->handleClick(50, 10); // past the rows: clamps to the last row
        $widget->handleInput(' ');
        $this->assertSame([1 => [1 => true]], $captured, 'clamped to the final +tail! row');

        $empty = new DiffViewWidget(new Translator('en'), events: $events);
        $empty->setDocument(null, null);
        $empty->handleClick(2, 2); // no rows: a safe no-op
        $this->assertFalse($empty->isSplit());
    }

    public function testReadOnlyStillAllowsTheLayoutToggle(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->setFocused(true);
        $widget->makeReadOnly();
        $widget->handleInput('s');
        $this->assertTrue($widget->isSplit(), 'split is display-only, fine for history diffs');

        // setSplit applies a preference silently (no hook fire).
        $fired = false;
        GitWidgetEvents::wire($events, onLayoutToggle: static function () use (&$fired): void {
            $fired = true;
        });
        $widget->useUnifiedView();
        $this->assertFalse($widget->isSplit());
        $widget->useUnifiedView(); // idempotent
        $this->assertFalse($fired);
    }

    public function testWhitespaceTagFiresTheHookAndMirrorsTheState(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $fired = 0;
        GitWidgetEvents::wire($events, onToggleWhitespace: static function () use (&$fired): void {
            ++$fired;
        });
        $widget->render(new RenderContext(80, 12));

        // ' W hide-ws ' spans cols 67-78 at 80 cols.
        $widget->handleClick(0, 70);
        $this->assertSame(1, $fired);

        $widget->setFocused(true);
        $widget->makeReadOnly();
        $widget->handleInput('w'); // allowed on read-only history diffs
        $this->assertSame(2, $fired);

        // The tag reflects the controller-owned state.
        $off = $widget->render(new RenderContext(80, 12))[0];
        $this->assertStringNotContainsString("\x1b[38;5;231m\x1b[48;5;31m\x1b[1m W hide-ws ", $off, 'inactive until the controller says so');
        $widget->hideWhitespace();
        $widget->hideWhitespace(); // idempotent
        $on = $widget->render(new RenderContext(80, 12))[0];
        $this->assertStringContainsString("\x1b[38;5;231m\x1b[48;5;31m\x1b[1m W hide-ws ", $on, 'active tag renders selected');
    }

    public function testLayoutAndWhitespaceSettersAreIdempotentBothWays(): void
    {
        $widget = $this->widget();

        $widget->useSplitView();
        $this->assertTrue($widget->isSplit());
        $widget->useSplitView(); // idempotent (already split)
        $this->assertTrue($widget->isSplit());
        $widget->useUnifiedView();
        $this->assertFalse($widget->isSplit());

        $widget->hideWhitespace();
        $on = $widget->render(new RenderContext(80, 12))[0];
        $this->assertStringContainsString("\x1b[38;5;231m\x1b[48;5;31m\x1b[1m W hide-ws ", $on);
        $widget->showWhitespace();
        $widget->showWhitespace(); // idempotent (already shown)
        $off = $widget->render(new RenderContext(80, 12))[0];
        $this->assertStringNotContainsString("\x1b[38;5;231m\x1b[48;5;31m\x1b[1m W hide-ws ", $off, 'tag clears once whitespace is shown again');
    }

    public function testHorizontalScrollSlidesTextUnderPinnedGutters(): void
    {
        $long = \str_repeat('x', times: 30).'NEEDLE'.\str_repeat('y', times: 30);
        $widget = new DiffViewWidget(new Translator('en'));
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-old\n+{$long}\n"), 'x.txt');
        $widget->setFocused(true);

        $lines = $this->plainLines($widget, 40, 6);
        $this->assertStringNotContainsString('NEEDLE', \implode('', $lines), 'the needle starts off-screen');

        for ($i = 0; $i < 3; ++$i) {
            $widget->handleInput("\x1b[C"); // right ×3 = 24 cols
        }
        $lines = $this->plainLines($widget, 40, 6);
        $this->assertStringContainsString('[→24]', $lines[0], 'the title shows the horizontal offset');
        $this->assertStringContainsString('NEEDLE', $lines[3], 'the needle scrolled into view');
        $this->assertMatchesRegularExpression('/^\s+1 │\+…/', $lines[3], 'gutter and marker stay pinned; … marks the cut');

        for ($i = 0; $i < 20; ++$i) {
            $widget->handleInput("\x1b[D"); // left clamps at 0
        }
        $lines = $this->plainLines($widget, 40, 6);
        $this->assertStringNotContainsString('[→', $lines[0]);
        $this->assertStringContainsString('+xxxx', $lines[3]);

        for ($i = 0; $i < 50; ++$i) {
            $widget->handleInput("\x1b[C"); // right clamps near the widest line
        }
        $this->assertSame(46, $widget->hScroll(), 'clamped to maxLineWidth (66) - 20');
    }

    public function testHorizontalScrollAppliesToBothSplitHalvesAndResetsPerPath(): void
    {
        $long = \str_repeat('a', times: 25).'LEFT'.\str_repeat('b', times: 20);
        $longer = \str_repeat('c', times: 25).'RIGHT'.\str_repeat('d', times: 20);
        $widget = new DiffViewWidget(new Translator('en'));
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-{$long}\n+{$longer}\n"), 'x.txt');
        $widget->useSplitView();
        $widget->setFocused(true);

        $widget->makeReadOnly(); // history diffs may scroll sideways too
        for ($i = 0; $i < 3; ++$i) {
            $widget->handleInput("\x1b[C");
        }
        $line = $this->plainLines($widget, 80, 6)[2];
        $this->assertStringContainsString('LEFT', $line, 'the old half scrolled');
        $this->assertStringContainsString('RIGHT', $line, 'the new half scrolled in step');

        // A different path resets the horizontal offset; same path keeps it.
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-a\n+b\n"), 'x.txt');
        $this->assertSame(24, $widget->hScroll(), 'same-path reload keeps the offset');
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-a\n+b\n"), 'other.txt');
        $this->assertSame(0, $widget->hScroll());
    }

    public function testEmphasisSurvivesTheHorizontalSlice(): void
    {
        // Paired lines sharing prefix/suffix get an intraline range; scroll so
        // the emphasized middle is inside the window and check its bg code.
        $widget = new DiffViewWidget(new Translator('en'));
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-".\str_repeat('p', times: 30)."OLD tail\n+".\str_repeat('p', times: 30)."NEW tail\n"), 'x.txt');
        $widget->setFocused(true);
        for ($i = 0; $i < 3; ++$i) {
            $widget->handleInput("\x1b[C"); // 24 cols: the changed middle is in view
        }

        $raw = $widget->render(new RenderContext(40, 6));
        $this->assertStringContainsString("\x1b[48;5;88m", $raw[2], 'the deletion emphasis bg survived the slice');
        $this->assertStringContainsString("\x1b[48;5;22m", $raw[3], 'the addition emphasis bg survived the slice');
    }

    public function testCtrlCCopiesTheCursorRowRangesAndHunks(): void
    {
        $events = new EventDispatcher();
        $widget = $this->widget($events);
        $widget->setFocused(true);
        $copies = [];
        GitWidgetEvents::wire($events, onCopy: static function (string $text) use (&$copies): void {
            $copies[] = $text;
        });
        // Cursor on the hunk header: the whole hunk's plain text.
        $widget->handleInput("\x03");
        $this->assertSame(["\$keep = 1;\n\$old = 2;\n\$new = 2;\n\$extra = 3;\n"], $copies);

        // A single ± row copies just that line's text.
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B"); // on -$old
        $widget->handleInput("\x03");
        $this->assertSame("\$old = 2;\n", $copies[1]);

        // A v-range joins the ranged rows in document order.
        $widget->handleInput('v');
        $widget->handleInput("\x1b[B"); // include +$new
        $widget->handleInput("\x03");
        $this->assertSame("\$old = 2;\n\$new = 2;\n", $copies[2]);
        $widget->handleInput("\x03"); // the range was consumed: back to single-row
        $this->assertSame("\$new = 2;\n", $copies[3]);
    }

    public function testCopySkipsNoNewlineMarkersAndWorksSplitAndReadOnly(): void
    {
        $events = new EventDispatcher();
        $widget = new DiffViewWidget(new Translator('en'), events: $events);
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-old\n\\ No newline at end of file\n+new\n"), 'x.txt');
        $widget->setFocused(true);
        $widget->makeReadOnly(); // history diffs can select + copy...
        $staged = false;
        GitWidgetEvents::wire($events, onStageSelection: static function () use (&$staged): void {
            $staged = true;
        });
        $copies = [];
        GitWidgetEvents::wire($events, onCopy: static function (string $text) use (&$copies): void {
            $copies[] = $text;
        });
        $widget->useSplitView(); // the -old/\-marker rows precede the +new pair
        $widget->handleInput("\x1b[B"); // first pair row (-old | blank)
        $widget->handleInput('v'); // allowed while read-only now
        $widget->handleInput("\x1b[B");
        $widget->handleInput("\x1b[B"); // through the marker row to (+new)
        $widget->handleInput(' '); // staging stays blocked
        $this->assertFalse($staged);
        $widget->handleInput("\x03");
        $this->assertSame(["old\nnew\n"], $copies, 'both sides, marker line skipped, still read-only');

        $empty = new DiffViewWidget(new Translator('en'), events: $events);
        $empty->setDocument(null, null);
        GitWidgetEvents::wire($events, onCopy: static function () use (&$copies): void {
            $copies[] = 'never';
        });
        $empty->handleInput("\x03");
        $this->assertCount(1, $copies, 'no document: nothing fires');
    }

    public function testDragSelectsCharsAcrossRowsAndAClickClearsIt(): void
    {
        $events = new EventDispatcher();
        // Rows: title, hunk, ' $keep = 1;', '-$old = 2;', '+$new = 2;'...
        // Text columns start at widget col 12 (gutter 11 + marker).
        $widget = $this->widget($events);
        $widget->setFocused(true);
        $this->plainLines($widget); // teach the viewport geometry
        $copies = [];
        GitWidgetEvents::wire($events, onCopy: static function (string $text) use (&$copies): void {
            $copies[] = $text;
        });
        // Press mid '-$old = 2;' (text col 1 = 'old = 2;') and drag to the
        // next row's text col 4: tail of the old line + head of the new one.
        $widget->handleClick(3, 13);
        $widget->dragSelectTo(4, 16);
        $raw = $widget->render(new RenderContext(80, 12));
        $this->assertStringContainsString("\x1b[48;5;24m", $raw[3], 'the selected span carries the selection bg');
        $this->assertStringContainsString("\x1b[48;5;24m", $raw[4]);
        $this->assertStringNotContainsString("\x1b[48;5;24m", $raw[2], 'rows outside the span stay untinted');
        $widget->handleInput("\x03");
        $this->assertSame(["old = 2;\n\$new"], $copies, 'exact chars: tail of the deletion, head of the addition');

        // The selection was consumed; a plain click + copy is row-based again.
        $widget->handleClick(4, 20);
        $widget->handleInput("\x03");
        $this->assertSame("\$new = 2;\n", $copies[1], 'a degenerate press falls back to the cursor row');

        // A reversed drag (upward) normalizes.
        $widget->handleClick(4, 16);
        $widget->dragSelectTo(3, 13);
        $widget->handleInput("\x03");
        $this->assertSame("old = 2;\n\$new", $copies[2]);

        // A span across the second hunk header: the header row contributes
        // nothing to the tint or the copied text.
        $widget->handleClick(5, 13); // +$extra = 3;
        $widget->dragSelectTo(7, 16); // through '@@ -10,1 +11,1 @@' to -tail
        $widget->render(new RenderContext(80, 12)); // tints skip the header row
        $widget->handleInput("\x03");
        $this->assertSame("extra = 3;\ntail", $copies[3], 'hunk header skipped mid-selection');

        $empty = new DiffViewWidget(new Translator('en'), events: $events);
        $empty->setDocument(null, null);
        $empty->dragSelectTo(3, 5); // no rows / no press: a safe no-op
        $this->assertFalse($empty->isSplit());
    }

    public function testSplitDragBindsToItsHalf(): void
    {
        $events = new EventDispatcher();
        $widget = new DiffViewWidget(new Translator('en'), events: $events);
        $widget->setDocument(DiffDocument::parse("@@ -1,2 +1,2 @@\n-oldA\n-oldB\n+newA\n+newB\n"), 'x.txt');
        $widget->useSplitView();
        $widget->setFocused(true);
        $widget->render(new RenderContext(80, 6)); // leftW = 39: right half text starts at col 46
        $copies = [];
        GitWidgetEvents::wire($events, onCopy: static function (string $text) use (&$copies): void {
            $copies[] = $text;
        });
        // Press in the RIGHT half (col 46 = new-side text col 0) and drag down
        // past the divider into the left half: the selection stays on the new
        // side, the out-of-half column clamps to its start.
        $widget->handleClick(2, 46);
        $widget->dragSelectTo(3, 50);
        $raw = $widget->render(new RenderContext(80, 6));
        $this->assertStringContainsString("\x1b[48;5;24m", $raw[2], 'the right-half span is tinted');
        $widget->handleInput("\x03");
        $this->assertSame(["newA\nnewB"], $copies, 'only new-side texts, cols projected onto the right half');

        // Press in the LEFT half: old-side text only.
        $widget->handleClick(2, 6);
        $widget->dragSelectTo(3, 8);
        $raw = $widget->render(new RenderContext(80, 6));
        $this->assertStringContainsString("\x1b[48;5;24m", $raw[2], 'the left-half span is tinted');
        $widget->handleInput("\x03");
        $this->assertSame("oldA\nol", $copies[1]);

        // Staging Space is unaffected by a char selection (no v anchor armed).
        $staged = null;
        GitWidgetEvents::wire($events, onStageSelection: static function (array $sel) use (&$staged): void {
            $staged = $sel;
        });
        $widget->handleClick(2, 6);
        $widget->dragSelectTo(3, 8);
        $widget->makeEditable();
        $widget->handleInput(' ');
        $this->assertSame([0 => [1 => true, 3 => true]], $staged, 'Space stages the cursor pair row, not the char span');
    }

    public function testSelectionBgSurvivesResetsInsideTheSpan(): void
    {
        // The right split half holds syntax-highlighted additions whose SGR
        // (and truncateToWidth's pad-boundary \x1b[0m) reset the background
        // mid-span; the selection must re-apply its bg after every reset.
        $widget = new DiffViewWidget(new Translator('en'));
        $widget->setDocument(DiffDocument::parse("@@ -1,1 +1,1 @@\n-\$a = 1;\n+\$a = 22;\n"), 'src/demo.php');
        $widget->useSplitView();
        $widget->setFocused(false);
        $widget->render(new RenderContext(60, 6)); // leftW = 29: right text starts col 36

        // Select from the right-half text start well past the line's end
        // (into the padding, which sits behind a \x1b[0m reset).
        $widget->handleClick(2, 36);
        $widget->dragSelectTo(2, 55);
        $row = $widget->render(new RenderContext(60, 6))[2];

        $this->assertGreaterThanOrEqual(
            2,
            \substr_count($row, needle: "\x1b[48;5;24m"),
            'the selection bg is re-applied after resets inside the span',
        );
        // After a bg reset inside the span (the intraline emphasis closes with
        // \x1b[49m) the selection bg must come straight back.
        $this->assertNotFalse(\strpos($row, needle: "\x1b[49m\x1b[48;5;24m"), 'a re-applied bg follows a reset inside the span');
    }

    public function testTheToolbarToggleUnderThePointerTints(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(80, 12));

        // ' S split ' spans cols 58-65, ' W hide-ws ' 67-78 at 80 cols.
        $this->assertTrue($widget->hoverHeader(0, 60), 'the pointer landed on the split toggle');
        $this->assertFalse($widget->hoverHeader(0, 61), 'still the same toggle → no change');
        $this->assertTrue($widget->hoverHeader(0, 70), 'moved onto the whitespace toggle');
        $this->assertTrue($widget->hoverHeader(0, 5), 'the title is not a toggle: the tint clears');
        $this->assertFalse($widget->hoverHeader(0, 5), 'still no toggle → no change');
        $this->assertTrue($widget->hoverHeader(0, 60), 'back onto a toggle');
        $this->assertTrue($widget->hoverHeader(4, 60), 'the toolbar is row 0 only: a body row clears the tint');
        $this->assertFalse($widget->hoverHeader(4, 60), 'still off the toolbar → no change');
        $this->assertTrue($widget->hoverHeader(0, 60), 'back onto a toggle');
        $this->assertTrue($widget->clearHeaderHover(), 'the pointer left the pane');
        $this->assertFalse($widget->clearHeaderHover(), 'nothing left to clear');
    }

    public function testAnOffToggleTintsUnderThePointerAndAnOnOneKeepsItsSelection(): void
    {
        $widget = $this->widget();
        $widget->render(new RenderContext(80, 12));

        $widget->hoverHeader(0, 70); // the whitespace toggle, currently off
        $off = $widget->render(new RenderContext(80, 12))[0];
        $this->assertStringContainsString("\x1b[48;5;236m", $off, 'an off toggle takes the hover tint');
        $this->assertSame(80, AnsiUtils::visibleWidth($off), 'the tint does not change the toolbar width');

        // An ON tag already carries the bright selection background, so the
        // hover must not repaint it away.
        $widget->hideWhitespace();
        $on = $widget->render(new RenderContext(80, 12))[0];
        $this->assertStringContainsString("\x1b[48;5;31m", $on, 'the on toggle keeps its selection background under the pointer');
        $this->assertSame(80, AnsiUtils::visibleWidth($on));
    }

    public function testATeamThemeSwitchRebuildsTheBakedRows(): void
    {
        // The rows cache by CONTENT, not by theme, so a live theme switch has
        // to bust them explicitly — which is the whole reason this widget
        // implements ThemeRefreshable and the applier sweeps every region.
        // Without it the diff keeps painting the previous palette until the
        // document changes, which no screen test elsewhere would catch.
        $widget = $this->widget();
        $before = \implode("\n", $widget->render(new RenderContext(80, 20)));

        $widget->refreshTheme();

        $this->assertSame($before, \implode("\n", $widget->render(new RenderContext(80, 20))), 'same theme, same rows — the rebuild is not a repaint bug');
        $this->assertStringContainsString('$new = 2;', AnsiUtils::stripAnsiCodes($before), 'and the rows survived the rebuild');
    }
}
