<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

use Porta\GitPlugin\ChangeEntry;

/** A discard was CONFIRMED — the widget's two-press guard already passed. */
final readonly class GitChangeDiscardRequested
{
    public function __construct(
        public ChangeEntry $entry,
    ) {}
}
