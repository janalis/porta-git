<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * What git is in the middle of: a merge, a rebase, a cherry-pick or a revert
 * that stopped — on a conflict, usually — and waits to be continued, skipped
 * or aborted.
 */
enum GitOperation
{
    case Merging;
    case Rebasing;
    case CherryPicking;
    case Reverting;

    /** The git command that continues, skips or aborts it. */
    public function command(): string
    {
        return match ($this) {
            self::Merging => 'merge',
            self::Rebasing => 'rebase',
            self::CherryPicking => 'cherry-pick',
            self::Reverting => 'revert',
        };
    }

    /** Whether the step that stopped can be dropped and the rest carried on: not a merge's one step. */
    public function skippable(): bool
    {
        return self::Merging !== $this;
    }
}
