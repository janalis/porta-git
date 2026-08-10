<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

use Porta\GitPlugin\LogEntry;

/** A file within a commit was picked: show that file's diff at that commit. */
final readonly class GitCommitFileSelected
{
    public function __construct(
        public LogEntry $commit,
        public string $path,
    ) {}
}
