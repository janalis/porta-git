<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\GitPlugin\Command\GitCommands;
use Porta\GitPlugin\Command\OperationCommands;
use Porta\GitPlugin\Command\WorktreeCommands;
use Porta\GitPlugin\Support\Translate;
use Porta\Workbench\Menu\MenuItem;

/**
 * The branch and worktree verbs as menu items, for the two places that drop
 * them: the status bar's branch cell (all of them) and the git view's toolbar,
 * whose Branch cell lists the branch verbs and whose Repository cell the
 * worktree ones — GitHub Desktop's split. While a merge or a rebase is in
 * progress, the branch verbs open on what to do with it.
 */
final readonly class BranchMenu
{
    public function __construct(
        private GitCommands $commands,
        private WorktreeCommands $worktrees,
        private Translate $translator,
        private OperationCommands $operations,
        private GitRefresher $refresher,
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
            ...$this->operation(),
            new MenuItem($this->translator->trans('Switch Branch…'), '', fn () => $this->commands->branches()),
            new MenuItem($this->translator->trans('New Branch…'), '', fn () => $this->commands->newBranch()),
            new MenuItem($this->translator->trans('Delete Branch…'), '', fn () => $this->commands->deleteBranch()),
        ];
    }

    /**
     * Continue, skip and abort, while git is in the middle of something —
     * skip only where there is a step to drop.
     *
     * @return list<MenuItem>
     */
    private function operation(): array
    {
        $state = $this->refresher->operation();
        if (null === $state) {
            return [];
        }
        $named = ['%operation%' => $this->operations->name($state->operation)];

        return [
            new MenuItem($this->translator->trans('Continue %operation%', $named), '', fn () => $this->operations->continue()),
            ...($state->operation->skippable() ? [new MenuItem($this->translator->trans('Skip This Step'), '', fn () => $this->operations->skip())] : []),
            new MenuItem($this->translator->trans('Abort %operation%…', $named), '', fn () => $this->operations->abort()),
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
