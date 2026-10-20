<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * The lines a commit added and removed, over all the files it changed.
 */
final readonly class DiffStat
{
    public function __construct(
        public int $added,
        public int $deleted,
    ) {}
}
