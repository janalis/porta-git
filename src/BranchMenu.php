<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\GitPlugin\Command\GitCommands;
use Porta\GitPlugin\Command\WorktreeCommands;
use Porta\GitPlugin\Support\Translate;
use Porta\Workbench\Menu\MenuItem;

/**
 * The branch and worktree verbs as menu items, for the two places that drop
 * them: the status bar's branch cell (all of them) and the git view's toolbar,
 * whose Branch cell lists the branch verbs and whose Repository cell the
 * worktree ones — GitHub Desktop's split.
 */
final readonly class BranchMenu
{
    public function __construct(
        private GitCommands $commands,
        private WorktreeCommands $worktrees,
        private Translate $translator,
    ) {}

    /** @return list<MenuItem> */
    public function all(): array
    {
        return [...$this->branches(), ...$this->worktrees()];
    }

    /** @return list<MenuItem> */
    public function branches(): array
    {
        return [
            new MenuItem($this->translator->trans('Switch Branch…'), '', fn () => $this->commands->branches()),
            new MenuItem($this->translator->trans('New Branch…'), '', fn () => $this->commands->newBranch()),
            new MenuItem($this->translator->trans('Delete Branch…'), '', fn () => $this->commands->deleteBranch()),
        ];
    }

    /** @return list<MenuItem> */
    public function worktrees(): array
    {
        return [
            new MenuItem($this->translator->trans('Worktrees…'), '', fn () => $this->worktrees->worktrees()),
            new MenuItem($this->translator->trans('New Worktree…'), '', fn () => $this->worktrees->newWorktree()),
        ];
    }
}
