<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\GitPlugin\Widget\DiffViewWidget;

/**
 * The kind of a flat render row emitted by {@see DiffDocument}: a hunk header, a
 * unified body line, or a side-by-side old/new pair. Drives selection collection
 * and per-row rendering in {@see \Porta\GitPlugin\Widget\DiffViewWidget}.
 */
enum DiffRowKind
{
    case Hunk;
    case Line;
    case Pair;
}
