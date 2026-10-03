<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\GitPlugin\Command\GitCommands;
use Porta\GitPlugin\Command\WorktreeCommands;
use Porta\GitPlugin\Setting\GitSettings;
use Porta\GitPlugin\Support\Translate;
use Porta\Ui\Glyphs;
use Porta\Workbench\Menu\MenuItem;
use Porta\Workbench\StatusCell;
use Porta\Workbench\StatusTone;

/**
 * The branch indicator, as an ordinary contributed status segment.
 *
 * `Porta\Workbench\StatusBarProvider` used to build this cell itself, holding
 * a refresher and a settings holder as constructor dependencies and publishing
 * a `branchStart`/`branchEnd` pair so the pointer router could hit-test this one
 * cell and open this one menu. Three core classes naming git to make one span
 * clickable. The cell carries its own menu now, and the status bar knows
 * neither what is in it nor who put it there — which is the whole reason this
 * class could leave `src/` with the rest of the view.
 *
 * It returns a {@see StatusCell} rather than a string, so it keeps its accent
 * and its right-click menu instead of being folded into the shared plugin cell.
 */
final readonly class GitBranchSegment
{
    public function __construct(
        private GitRefresher $refresher,
        private GitSettings $settings,
        private GitCommands $commands,
        private Translate $translator,
        private WorktreeCommands $worktrees,
        /** @var string|null the linked worktree this project is, by name — null for the main one */
        private ?string $worktree = null,
    ) {}

    /** The cell, or null when the setting is off or git has not answered yet. */
    public function statusCell(): ?StatusCell
    {
        if (!$this->settings->showBranch) {
            return null;
        }
        $branch = $this->refresher->branch();
        if (null === $branch) {
            return null;
        }

        // In a linked worktree, which one: several of them open at once read
        // as the same project otherwise, each on its own branch.
        $where = null === $this->worktree ? '' : ' '.Glyphs::WORKTREE." {$this->worktree}";

        return new StatusCell(
            Glyphs::STATUS_BRANCH." {$branch}{$where}",
            StatusTone::Accent,
            $this->branchItems(...),
            null === $this->worktree
                ? $this->translator->trans('Git branch %branch% — click to switch, create or delete a branch', ['%branch%' => $branch])
                : $this->translator->trans('Git branch %branch% in worktree %name% — click to switch branch or worktree', ['%branch%' => $branch, '%name%' => $this->worktree]),
        );
    }

    /**
     * What a right-click on the branch offers. These were three entries in
     * `ContextMenuBuilder::branchItems()`, reached through a git-shaped branch
     * in the context-menu router.
     *
     * @return list<MenuItem>
     */
    private function branchItems(): array
    {
        return [
            new MenuItem($this->translator->trans('Switch Branch…'), '', fn () => $this->commands->branches()),
            new MenuItem($this->translator->trans('New Branch…'), '', fn () => $this->commands->newBranch()),
            new MenuItem($this->translator->trans('Delete Branch…'), '', fn () => $this->commands->deleteBranch()),
            new MenuItem($this->translator->trans('Worktrees…'), '', fn () => $this->worktrees->worktrees()),
            new MenuItem($this->translator->trans('New Worktree…'), '', fn () => $this->worktrees->newWorktree()),
        ];
    }
}
