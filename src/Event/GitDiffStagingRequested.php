<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

/**
 * Stage or unstage the selected diff lines.
 *
 * The selection is hunk index => set of line indices — what the widget has, in
 * the widget's own coordinates, because only it knows what the cursor and any
 * armed anchor cover.
 *
 * @phpstan-type Selection array<int, array<int, true>>
 */
final readonly class GitDiffStagingRequested
{
    /** @param array<int, array<int, true>> $selection */
    public function __construct(
        public array $selection,
    ) {}
}
