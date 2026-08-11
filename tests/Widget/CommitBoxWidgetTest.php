<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Command\CommitMode;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\RendersWidget;
use Porta\Ui\Glyphs;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\CursorShape;

final class CommitBoxWidgetTest extends TestCase
{
    use RendersWidget;

    private bool $staged = true;

    private function widget(?string &$committed = null, ?bool &$amended = null): CommitBoxWidget
    {
        $widget = new CommitBoxWidget(new Translator('en'));
        $widget->setFocused(true);
        $widget->setStagedChangesProvider(fn (): bool => $this->staged);
        $widget->onCommit(static function (string $message, CommitMode $amend) use (&$committed, &$amended): void {
            $committed = $message;
            $amended = CommitMode::Amend === $amend;
        });

        return $widget;
    }

    private function type(CommitBoxWidget $widget, string $text): void
    {
        foreach (str_split($text) as $ch) {
            $widget->handleInput($ch);
        }
    }

    private function rendered(CommitBoxWidget $widget): string
    {
        return $this->plain($widget->render(new RenderContext(44, 10)));
    }

    public function testMessageAssemblyAndEnabledState(): void
    {
        $widget = $this->widget();
        $this->assertFalse($widget->commitEnabled(), 'empty summary disables commit');

        $this->type($widget, 'Fix parser');
        $this->assertTrue($widget->commitEnabled());
        $this->assertSame('Fix parser', $widget->message(), 'no blank body appended without a description');

        $widget->handleInput("\t"); // into the description
        $this->type($widget, 'Long');
        $widget->handleInput("\r");
        $this->type($widget, 'body');
        $this->assertSame("Fix parser\n\nLong\nbody", $widget->message());

        $this->staged = false;
        $this->assertFalse($widget->commitEnabled(), 'nothing staged disables commit');
    }

    public function testCtrlEnterCommitsOnlyWhenEnabled(): void
    {
        $widget = $this->widget($committed);

        $widget->handleInput("\x1b[13;5u"); // ctrl+enter with no summary
        $this->assertNull($committed);

        $this->type($widget, 'Do it');
        $widget->handleInput("\x1b[13;5u");
        $this->assertSame('Do it', $committed);
    }

    public function testButtonClickCommitsAndFieldClicksMoveTheCaret(): void
    {
        $widget = $this->widget($committed);
        $this->type($widget, 'Click me');

        $widget->handleClick(2); // description rows start at rel row 2
        $this->type($widget, 'desc');
        $this->assertSame("Click me\n\ndesc", $widget->message());

        $widget->handleClick(1); // back to the summary row
        $this->type($widget, '!');
        $this->assertStringStartsWith('Click me!', $widget->message());

        $widget->handleClick($widget->buttonRow());
        $this->assertSame("Click me!\n\ndesc", $committed);
    }

    public function testTabWalksSummaryDescriptionThenNextPane(): void
    {
        $widget = $this->widget();
        $focusNext = 0;
        $widget->onFocusNext(static function () use (&$focusNext): void {
            ++$focusNext;
        });

        $widget->handleInput("\t"); // summary -> description
        $this->assertSame(0, $focusNext);
        $widget->handleInput("\t"); // description -> next pane
        $this->assertSame(1, $focusNext);
    }

    public function testRenderShowsPlaceholdersButtonStateAndClearResets(): void
    {
        $widget = new CommitBoxWidget(new Translator('en'));
        $widget->setStagedChangesProvider(static fn (): bool => true);

        $screen = $this->rendered($widget);
        $this->assertStringContainsString('Summary (required)', $screen);
        $this->assertStringContainsString('Description', $screen);
        $this->assertStringContainsString('Commit to branch', $screen);

        $widget->setFocused(true);
        $this->type($widget, 'Msg');
        $raw = $widget->render(new RenderContext(44, 10));
        $this->assertStringContainsString("\x1b[48;5;31m", implode('', $raw), 'enabled button uses the selected style');

        $widget->clear();
        $this->assertSame('', $widget->message());
        $widget->setFocused(false); // a focused empty field shows the caret, not the hint
        $this->assertStringContainsString('Summary (required)', $this->rendered($widget));
    }

    public function testSummaryBackspaceAndResetField(): void
    {
        $widget = $this->widget();
        $this->type($widget, 'abc');
        $widget->handleInput("\x7f");
        $this->assertSame('ab', $widget->message());

        $widget->handleInput("\t"); // into the description
        $this->type($widget, 'x');
        $widget->resetField(); // pane re-entry lands on the summary
        $this->type($widget, '!');
        $this->assertSame("ab!\n\nx", $widget->message());
    }

    public function testInputHookConsumesFirst(): void
    {
        $widget = $this->widget();
        $widget->onInput(static fn (string $data): bool => true);
        $this->type($widget, 'swallowed');
        $this->assertSame('', $widget->message());
    }

    public function testAmendToggleEnablesCommitWithoutStagedChangesAndFlagsSubmit(): void
    {
        $this->staged = false;
        $widget = $this->widget($committed, $amended);
        $toggles = [];
        $widget->onAmendToggle(static function (CommitMode $amend) use (&$toggles): void {
            $toggles[] = CommitMode::Amend === $amend;
        });

        $this->type($widget, 'Reword');
        $this->assertFalse($widget->commitEnabled(), 'nothing staged, no amend: disabled');

        $widget->handleInput("\x1bm"); // alt+m
        $this->assertSame([true], $toggles);
        $this->assertTrue($widget->isAmend());
        $this->assertTrue($widget->commitEnabled(), 'amending only the message needs nothing staged');

        $screen = $this->rendered($widget);
        $this->assertStringContainsString('Alt+M amend '.Glyphs::CHECKBOX_ON, $screen, 'the amend state reads as a checkbox, without colour');
        $this->assertStringContainsString('Amend last commit', $screen);

        $widget->handleInput("\x1b[13;5u");
        $this->assertSame('Reword', $committed);
        $this->assertTrue($amended);

        $widget->handleInput("\x1bm"); // toggle back off
        $this->assertSame([true, false], $toggles);
        $this->assertFalse($widget->isAmend());
    }

    public function testSetMessagePrefillsSummaryAndDescriptionAndClearResetsAmend(): void
    {
        $widget = $this->widget();
        $widget->handleInput("\x1bm");

        $widget->setMessage("A subject\nwrapped\n\nbody line 1\nbody line 2");
        $this->assertSame("A subject wrapped\n\nbody line 1\nbody line 2", $widget->message(), 'summary newlines flatten; body survives verbatim');

        $widget->setMessage('Just a subject');
        $this->assertSame('Just a subject', $widget->message(), 'a summary-only prefill clears the old description');

        $widget->clear();
        $this->assertFalse($widget->isAmend(), 'clear() disarms amend');
        $this->assertSame('', $widget->message());
    }

    public function testEscapeFiresExit(): void
    {
        $widget = $this->widget();
        $exited = false;
        $widget->onExit(static function () use (&$exited): void {
            $exited = true;
        });

        $widget->handleInput("\x1b");
        $this->assertTrue($exited);
    }

    public function testAmendPrefillOfAMultiLineBodyKeepsTheCaretVisible(): void
    {
        $widget = $this->widget();
        $widget->handleInput("\t"); // focus the description field (activeField = 1)
        // Amend prefills the last commit; a 4+-line body used to park the caret
        // below the 3-row window and drop its cursor marker (frozen UI on backspace).
        $widget->setMessage("summary\n\nl1\nl2\nl3\nl4\nl5");

        $lines = $widget->render(new RenderContext(44, 10));
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);
        $markers = array_sum(array_map(static fn (string $l): int => substr_count($l, $marker), $lines));

        $this->assertSame(1, $markers, 'the description caret marker stays inside the visible window');
        $this->assertStringContainsString('l5', $this->rendered($widget), 'the window scrolled to the caret line');
    }

    private function markerCount(CommitBoxWidget $widget): int
    {
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);

        return array_sum(array_map(static fn (string $l): int => substr_count($l, $marker), $widget->render(new RenderContext(44, 10))));
    }

    public function testLongDescriptionLineKeepsTheCaretVisible(): void
    {
        $widget = $this->widget();
        $widget->handleInput("\t"); // focus the description
        $this->type($widget, str_repeat('x', times: 60)); // overflow the 44-column box

        $this->assertSame(1, $this->markerCount($widget), 'the description caret survives horizontal overflow');
    }

    public function testLongSummaryLineKeepsTheCaretVisible(): void
    {
        $widget = $this->widget();
        $this->type($widget, str_repeat('x', times: 60)); // summary is the default field

        $this->assertSame(1, $this->markerCount($widget), 'the summary caret survives horizontal overflow');
    }

    /** @return list<int> the visible width of every rendered line */
    private function lineWidths(CommitBoxWidget $widget): array
    {
        return array_map(AnsiUtils::visibleWidth(...), $widget->render(new RenderContext(44, 10)));
    }

    public function testBackspacingIntoAMultibyteDescriptionKeepsEveryLineAtWidth(): void
    {
        $widget = $this->widget();
        $widget->handleInput("\t"); // focus the description
        // Amend prefills the last commit — commonly with a multibyte char (em-dash).
        $widget->setMessage("summary\n\nRefactor the parser \u{2014} it was slow");

        // Backspace all the way through the em-dash and out; every frame must stay 44 wide.
        for ($i = 0; $i < 25; ++$i) {
            foreach ($this->lineWidths($widget) as $width) {
                $this->assertSame(44, $width, "a line overflowed the sidebar at backspace {$i}");
            }
            $widget->handleInput("\x7f");
        }
    }

    public function testBackspacingIntoAMultibyteSummaryKeepsEveryLineAtWidth(): void
    {
        $widget = $this->widget();
        $this->type($widget, 'release '); // summary is the default field
        $widget->handleInput("\u{2014}"); // an em-dash

        foreach ($this->lineWidths($widget) as $width) {
            $this->assertSame(44, $width);
        }
        $widget->handleInput("\x7f"); // backspace into the em-dash
        foreach ($this->lineWidths($widget) as $width) {
            $this->assertSame(44, $width, 'the summary row must not overflow after a multibyte backspace');
        }
    }

    public function testTheEnabledCommitButtonHoversAndTheDisabledOneDoesNot(): void
    {
        $widget = $this->widget();
        $this->assertFalse($widget->hoverRow($widget->buttonRow()), 'a disabled button is not actionable, so it never hovers');

        $this->type($widget, 'feat: something');
        $this->assertTrue($widget->hoverRow($widget->buttonRow()), 'the enabled button reacts under the pointer');
        $this->assertFalse($widget->hoverRow($widget->buttonRow()));
        $lines = $widget->render(new RenderContext(44, 12));
        $this->assertStringContainsString("\x1b[4m", $lines[$widget->buttonRow()], 'the hovered button underlines');

        $this->assertTrue($widget->hoverRow(0), 'the summary field is not the button — the tint clears');
        $this->assertStringNotContainsString("\x1b[4m", $widget->render(new RenderContext(44, 12))[$widget->buttonRow()]);
    }
}
