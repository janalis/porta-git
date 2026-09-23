<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Event;

/** The changes list's header checkbox: stage every change, or unstage every one. */
final readonly class GitStageAllRequested
{
    public function __construct(
        public bool $stage,
    ) {}
}
