<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

use Porta\GitPlugin\LogEntry;

/** The history list moved to a commit; its file list follows. */
final readonly class GitCommitSelected
{
    public function __construct(
        public LogEntry $commit,
    ) {}
}
