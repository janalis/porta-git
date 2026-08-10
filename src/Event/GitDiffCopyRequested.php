<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

/** Ctrl+C in the diff pane: the selected text, plain — no ± origin markers. */
final readonly class GitDiffCopyRequested
{
    public function __construct(
        public string $text,
    ) {}
}
