<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * A merge, rebase, cherry-pick or revert in progress: which, how far a rebase
 * has got — the step it stopped on of how many — and how many paths are
 * still conflicted.
 */
final readonly class OperationState
{
    public function __construct(
        public GitOperation $operation,
        public ?int $step = null,
        public ?int $total = null,
        public int $conflicts = 0,
    ) {}

    /** The same operation with $count conflicts left. */
    public function withConflicts(int $count): self
    {
        return new self($this->operation, $this->step, $this->total, $count);
    }
}
