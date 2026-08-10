<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

/**
 * The diff pane's column arithmetic, spelled ONCE: where the split boundary
 * sits and where a row's text begins per column. The mouse path
 * ({@see DiffViewWidget}) and the render path
 * ({@see \Porta\GitPlugin\Widget\DiffViewRenderer}) both read these — they used
 * to carry private copies, and a drift between them would make a drag
 * selection highlight different columns than it selected.
 */
final class DiffGeometry
{
    /** The split view's divider column (the left half's exclusive end). */
    public static function splitBoundary(int $width): int
    {
        return intdiv($width - 1, num2: 2);
    }

    /**
     * The widget column where a row's line text starts: after the unified
     * gutter (null), the left half's gutter, or the divider plus the right
     * half's gutter.
     */
    public static function textStart(?DiffColumn $column, int $width): int
    {
        return match ($column) {
            null => 12,
            DiffColumn::Old => 6,
            DiffColumn::New => self::splitBoundary($width) + 7,
        };
    }
}
