<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\GitPlugin\Setting\GitSettings;
use Porta\GitPlugin\Support\Translate;
use Porta\Ui\Glyphs;
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
        private BranchMenu $menu,
        private Translate $translator,
        /** @var string|null the linked worktree this project is, by name — null for the main one */
        private ?string $worktree = null,
    ) {}

    /**
     * The cell, or null when the setting is off or git has not answered yet.
     * A merge or rebase in progress is said on it, branch or no branch — a
     * rebase runs on a detached HEAD.
     */
    public function statusCell(): ?StatusCell
    {
        $branch = $this->settings->showBranch ? $this->refresher->branch() : null;
        $operation = $this->settings->showBranch ? $this->refresher->operation() : null;

        return match (true) {
            null !== $operation => $this->operationCell($branch, $operation),
            null === $branch => null,
            default => $this->branchCell($branch),
        };
    }

    private function branchCell(string $branch): StatusCell
    {
        // In a linked worktree, which one: several of them open at once read
        // as the same project otherwise, each on its own branch.
        $where = null === $this->worktree ? '' : ' '.Glyphs::WORKTREE." {$this->worktree}";

        return new StatusCell(
            Glyphs::STATUS_BRANCH." {$branch}{$where}",
            StatusTone::Accent,
            $this->menu->all(...),
            null === $this->worktree
                ? $this->translator->trans('Git branch %branch% — click to switch, create or delete a branch', ['%branch%' => $branch])
                : $this->translator->trans('Git branch %branch% in worktree %name% — click to switch branch or worktree', ['%branch%' => $branch, '%name%' => $this->worktree]),
        );
    }

    /** "⑂ main · rebasing 3/7 · 2 conflicted files": what is in progress, and what its menu does about it. */
    private function operationCell(?string $branch, OperationState $state): StatusCell
    {
        $progress = $this->translator->trans(match ($state->operation) {
            GitOperation::Merging => 'merging',
            GitOperation::Rebasing => 'rebasing',
            GitOperation::CherryPicking => 'cherry-picking',
            GitOperation::Reverting => 'reverting',
        });
        $parts = [
            ...(null === $branch ? [] : [$branch]),
            $progress.(null === $state->step || null === $state->total ? '' : " {$state->step}/{$state->total}"),
            ...(0 === $state->conflicts ? [] : [$this->translator->trans('%count% conflicted file|%count% conflicted files', ['%count%' => (string) $state->conflicts])]),
        ];

        return new StatusCell(
            Glyphs::STATUS_BRANCH.' '.\implode(' · ', $parts),
            StatusTone::Active,
            $this->menu->all(...),
            0 === $state->conflicts
                ? $this->translator->trans('Resolved — click to continue or abort')
                : $this->translator->trans('Resolve the conflicts, then click to continue — or skip or abort'),
        );
    }
}
