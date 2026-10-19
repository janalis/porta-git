<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/** How far a branch has drifted from its upstream: commits only here, and commits only there. */
final readonly class Tracking
{
    public function __construct(
        public int $ahead,
        public int $behind,
    ) {}
}
