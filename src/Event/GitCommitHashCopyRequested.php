<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

use Porta\GitPlugin\LogEntry;

/** The commit header's copy button, or `y` in a commit's file list: the full sha to the clipboard. */
final readonly class GitCommitHashCopyRequested
{
    public function __construct(
        public LogEntry $commit,
    ) {}
}
