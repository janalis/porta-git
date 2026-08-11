<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\GitPlugin\Diff\DiffLine;
use Porta\GitPlugin\Diff\DiffSide;

/**
 * Which column of a side-by-side diff row a half renders: the old (pre-image)
 * or the new (post-image) one. Renamed from DiffSide so it can no longer be
 * confused with {@see \Porta\GitPlugin\Diff\DiffSide}, the staging axis. Owns the
 * line-number pick so the renderer asks the column rather than branching on a
 * bool.
 */
enum DiffColumn
{
    case Old;
    case New;

    /** The line number this side shows for $line (null when the side has no line here). */
    public function lineNo(DiffLine $line): ?int
    {
        return match ($this) {
            self::Old => $line->oldNo,
            self::New => $line->newNo,
        };
    }
}
