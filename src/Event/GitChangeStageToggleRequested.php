<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

use Porta\GitPlugin\ChangeEntry;

/** An entry's checkbox was toggled: stage it, or unstage it. */
final readonly class GitChangeStageToggleRequested
{
    public function __construct(
        public ChangeEntry $entry,
    ) {}
}
