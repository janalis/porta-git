<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

/** `c`: cycle the diff's context lines (default → ±10 → whole file). */
final readonly class GitDiffContextCycleRequested {}
