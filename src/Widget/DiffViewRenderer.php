<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\Diff\DiffDocument;
use Porta\GitPlugin\Diff\DiffLine;
use Porta\GitPlugin\Diff\DiffRowKind;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\EditorRowAssembler;
use Porta\Widget\Render\LineDecorator;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Renders one diff body row for {@see \Porta\GitPlugin\Widget\DiffViewWidget}. Built
 * per render() call from the widget's current view state (mirroring how the
 * editor builds {@see EditorRowAssembler}), so the widget keeps the diff model,
 * char-selection and input while the row assembly — gutter, ± marker,
 * horizontal-scroll slicing, intraline emphasis, selection/cursor tints — lives
 * here and is unit-testable without driving the whole widget.
 *
 * @phpstan-type Row array{kind: DiffRowKind, hunk: int, line?: int, left?: int, right?: int}
 */
final readonly class DiffViewRenderer
{
    /**
     * @param array<string, string> $highlighted syntax-highlighted body text keyed by "hunk:line"
     * @param ?DiffCharSelection     $charSel     the normalized char selection, or null
     */
    public function __construct(
        private DiffDocument $document,
        private array $highlighted,
        private int $hScroll,
        private int $cursor,
        private ?int $anchor,
        private bool $focused,
        private ?DiffCharSelection $charSel,
        private Styles $styles,
    ) {}

    /**
     * The fully decorated line for a body row: rendered, char-selection tinted, cursor/range shaded.
     *
     * @param Row $row
     */
    public function row(int $rowIndex, array $row, int $width): string
    {
        return $this->decorateRowLine($rowIndex, $this->applyCharSelectionTint($rowIndex, $row, $this->renderRow($rowIndex, $row, $width), $width));
    }

    /**
     * The DiffLine a render row shows (on $side in split mode), or null. Static
     * so the widget's char-selection code and this renderer share one source.
     *
     * @param Row $row
     */
    public static function lineAtRow(DiffDocument $document, array $row, ?DiffColumn $side): ?DiffLine
    {
        if (DiffRowKind::Hunk === $row['kind']) {
            return null;
        }
        $index = $row['line'] ?? -1;
        if (DiffRowKind::Line !== $row['kind']) {
            $index = DiffColumn::New !== $side ? $row['left'] ?? -1 : $row['right'] ?? -1;
        }

        return $index >= 0 ? $document->hunks[$row['hunk']]->lines[$index] : null;
    }

    /** The cursor/range background applied to a rendered diff row. */
    private function decorateRowLine(int $rowIndex, string $line): string
    {
        if ($this->focused && $rowIndex === $this->cursor) {
            return $this->styles->of(Role::HeaderBar)->apply($line);
        }
        if ($this->inRange($rowIndex)) {
            return $this->styles->of(Role::RowHighlightBg)->apply($line);
        }

        return $line;
    }

    /** Whether $rowIndex sits inside the armed `v` range. */
    private function inRange(int $rowIndex): bool
    {
        if (null === $this->anchor) {
            return false;
        }
        [$from, $to] = $this->anchor <= $this->cursor ? [$this->anchor, $this->cursor] : [$this->cursor, $this->anchor];

        return $rowIndex >= $from && $rowIndex <= $to;
    }

    /**
     * Tint the visible span of the char selection on a rendered row (the
     * selection's side only, in split mode), like the editor's selection bg.
     *
     * @param Row $row
     */
    private function applyCharSelectionTint(int $rowIndex, array $row, string $line, int $width): string
    {
        $sel = $this->charSel;
        if (null === $sel || $rowIndex < $sel->fromRow || $rowIndex > $sel->toRow) {
            return $line;
        }
        $target = self::lineAtRow($this->document, $row, $sel->side);
        if (null === $target) {
            return $line;
        }
        $textStart = DiffGeometry::textStart($sel->side, $width);
        $segEnd = DiffColumn::Old === $sel->side ? DiffGeometry::splitBoundary($width) : $width;
        $fromCol = $rowIndex === $sel->fromRow ? $sel->fromCol : 0;
        $toCol = $rowIndex === $sel->toRow ? $sel->toCol : mb_strwidth($target->text);
        $start = max($textStart, $textStart + $fromCol - $this->hScroll);
        $end = min($segEnd, $textStart + $toCol - $this->hScroll);

        // Re-apply the bg after any reset inside the span (syntax SGR resets,
        // truncateToWidth's pad boundary) — without it the highlight dies
        // mid-selection on highlighted lines.
        $selectionStyle = $this->styles->of(Role::SelectionBg);
        $selectionBg = $selectionStyle->getAnsiRestore();

        return $end <= $start ? $line : LineDecorator::apply($line, $start, $end, $selectionBg, Sgr::resetFor($selectionStyle), $selectionBg);
    }

    /**
     * Overlay the intraline changed range (byte offsets on the raw text,
     * converted to display columns) with a stronger background; the
     * ANSI-aware LineDecorator keeps any syntax SGR spans intact.
     */
    private function withEmphasis(string $rendered, DiffLine $line, string $bg): string
    {
        if (null === $line->changedRange) {
            return $rendered;
        }
        [$from, $to] = $line->changedRange;
        if ($from >= $to) {
            return $rendered;
        }
        $startCol = mb_strwidth(substr($line->text, offset: 0, length: $from));
        $endCol = $startCol + mb_strwidth(substr($line->text, $from, $to - $from));

        return LineDecorator::apply($rendered, $startCol, $endCol, $bg, Sgr::DEFAULT_BG, $bg);
    }

    /** @param Row $row */
    private function renderRow(int $rowIndex, array $row, int $width): string
    {
        $hunk = $this->document->hunks[$row['hunk']];

        if (DiffRowKind::Hunk === $row['kind']) {
            return $this->styles->of(Role::Hunk)->apply(AnsiText::truncate(' '.$hunk->header(), $width, pad: true));
        }
        if (DiffRowKind::Pair === $row['kind']) {
            $leftW = DiffGeometry::splitBoundary($width);
            $rightW = $width - 1 - $leftW;

            return (
                $this->renderHalf($row['hunk'], $row['left'] ?? -1, $leftW, DiffColumn::Old)
                .$this->styles->of(Role::Gutter)->apply(Glyphs::DIFF_COLUMN)
                .$this->renderHalf($row['hunk'], $row['right'] ?? -1, $rightW, DiffColumn::New)
                .Sgr::RESET
            );
        }

        $line = $hunk->lines[$row['line'] ?? -1];
        // Built plain and measured, then styled: the gutter is eleven fixed
        // columns and the marker one more, so a pane narrower than thirteen
        // cannot seat a row at all. `max(1, $width - 12)` bounded the TEXT and
        // not the row, which is a clamp with no floor — the row came out
        // thirteen columns wide however narrow the pane was, and a row wider
        // than its pane throws RenderException out of the render loop.
        $gutter =
            str_pad(null === $line->oldNo ? '' : (string) $line->oldNo, length: 4, pad_string: ' ', pad_type: \STR_PAD_LEFT)
            .' '
            .str_pad(null === $line->newNo ? '' : (string) $line->newNo, length: 4, pad_string: ' ', pad_type: \STR_PAD_LEFT)
            .' '
            .Glyphs::DIFF_COLUMN;
        $body = $width - mb_strwidth($gutter) - 1;
        if ($body < 1) {
            // Clipped BEFORE it is styled, never after: re-slicing an already
            // styled string cuts escape sequences in half.
            return $this->styles->of(Role::Gutter)->apply(AnsiText::truncate($gutter, $width, pad: true)).Sgr::RESET;
        }

        [$marker, $text] = $this->lineBody($line, $row['hunk'].':'.($row['line'] ?? -1));

        return $this->styles->of(Role::Gutter)->apply($gutter).$marker.$this->scrolledText($text, $body).Sgr::RESET;
    }

    /**
     * A body line split into its pinned one-column ± marker and the scrollable
     * styled text. The origin char and gutter mark the row kind; added/context
     * text keeps its syntax colors, deletions render whole-row red (the
     * pre-image no longer matches the file's highlight state). Paired ± lines
     * carry an intraline changed range, emphasized with a stronger background.
     *
     * @return array{0: string, 1: string}
     */
    private function lineBody(DiffLine $line, string $key): array
    {
        // The '-' and '\' markers deliberately leave their foreground open so
        // the whole row inherits it (deletions render whole-row red).
        return match ($line->origin) {
            '+' => [$this->styles->of(Role::Added)->apply('+'), $this->withEmphasis($this->highlighted[$key] ?? $line->text, $line, $this->styles->of(Role::AddEmphasisBg)->getAnsiRestore())],
            '-' => [$this->styles->of(Role::Deleted)->getAnsiRestore().'-', $this->withEmphasis($line->text, $line, $this->styles->of(Role::DelEmphasisBg)->getAnsiRestore())],
            '\\' => [$this->styles->of(Role::Dim)->getAnsiRestore().' ', $line->text],
            default => [' ', $this->highlighted[$key] ?? $line->text],
        };
    }

    /**
     * The visible window of a styled line text: sliced by the horizontal
     * scroll (ANSI-aware, so syntax/emphasis SGR survives) with a leading …
     * marking cut-off text, right-truncated and padded to $inner columns.
     */
    private function scrolledText(string $text, int $inner): string
    {
        if ($this->hScroll <= 0) {
            return AnsiText::truncate($text, $inner, pad: true);
        }

        $slice = AnsiUtils::sliceByColumn($text, $this->hScroll, $inner);

        // A LEADING mark: the cut is at the head, so the row reads as scrolled.
        return Glyphs::ELLIPSIS.AnsiText::truncate($slice, max(1, $inner - 1), pad: true);
    }

    /**
     * One side of a side-by-side row: 4-digit gutter + marker + content in
     * $width columns; -1 (no line on this side) renders blank. Colors reset
     * to the default foreground at the end so nothing bleeds past the divider.
     */
    private function renderHalf(int $hunkIndex, int $lineIndex, int $width, DiffColumn $side): string
    {
        if ($lineIndex < 0) {
            return AnsiText::blank($width);
        }
        $line = $this->document->hunks[$hunkIndex]->lines[$lineIndex];
        $no = $side->lineNo($line);
        // The same five-column gutter plus a marker, and the same floor: half
        // of a split pane is half as wide, so this side reaches the case first.
        $gutter = str_pad(null === $no ? '' : (string) $no, length: 4, pad_string: ' ', pad_type: \STR_PAD_LEFT).' ';
        $body = $width - mb_strwidth($gutter) - 1;
        if ($body < 1) {
            return $this->styles->of(Role::Gutter)->apply(AnsiText::truncate($gutter, $width, pad: true)).Sgr::DEFAULT_FG;
        }

        [$marker, $text] = $this->lineBody($line, $hunkIndex.':'.$lineIndex);

        return $this->styles->of(Role::Gutter)->apply($gutter).$marker.$this->scrolledText($text, $body).Sgr::DEFAULT_FG;
    }
}
