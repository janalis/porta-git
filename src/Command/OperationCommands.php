<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Command;

use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitOperation;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\OperationState;
use Porta\GitPlugin\OperationStep;
use Porta\GitPlugin\Support\Flash;
use Porta\GitPlugin\Support\Modals;
use Porta\GitPlugin\Support\Translate;
use Porta\Support\Outcome;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Continue, skip or abort the merge, rebase, cherry-pick or revert git is in
 * the middle of — from the palette, the branch cell's menu and the git view's
 * Branch menu alike.
 *
 * Continue is refused while a path is still conflicted, saying how many — as
 * git counts them NOW, since a file staged from a terminal moved nothing the
 * IDE heard. Git would refuse too, in its own words, but less plainly. Abort undoes every
 * resolution made so far, so it asks first. What git answers is flashed, and
 * the working tree is announced as moved either way — a continue that stops
 * on the next conflict moved it as much as one that finished.
 */
final readonly class OperationCommands
{
    public function __construct(
        private GitInfo $git,
        private GitRefresher $refresher,
        private Modals $modals,
        private Flash $notifier,
        private Translate $translator,
        private EventDispatcherInterface $dispatcher,
        /** @var \Closure(): void asks for a frame */
        private \Closure $repaint,
    ) {}

    public function continue(): void
    {
        $state = $this->inProgress();
        if (null === $state) {
            return;
        }
        $this->git->conflictCount(function (int $count) use ($state): void {
            if ($count > 0) {
                $this->notifier->warn($this->translator->trans('Resolve the conflicted files first: %count% left', ['%count%' => (string) $count]));

                return;
            }
            $this->run($state->operation, OperationStep::Continue);
        });
    }

    public function skip(): void
    {
        $state = $this->inProgress();
        if (null === $state) {
            return;
        }
        if (!$state->operation->skippable()) {
            $this->notifier->warn($this->translator->trans('A merge has no step to skip: continue or abort it'));

            return;
        }
        $this->run($state->operation, OperationStep::Skip);
    }

    public function abort(): void
    {
        $state = $this->inProgress();
        if (null === $state) {
            return;
        }
        $this->modals->openListOverlay(
            $this->translator->trans('Abort the %operation%? What you resolved is lost', ['%operation%' => $this->name($state->operation)]),
            [
                ['label' => $this->translator->trans('Abort'), 'hint' => '', 'value' => 'abort'],
                ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'cancel'],
            ],
            function (string $choice) use ($state): void {
                if ('abort' === $choice) {
                    $this->run($state->operation, OperationStep::Abort);
                }
            },
        );
        ($this->repaint)();
    }

    /** What a status line or a menu calls $operation: "rebase", "merge"… */
    public function name(GitOperation $operation): string
    {
        return match ($operation) {
            GitOperation::Merging => $this->translator->trans('merge'),
            GitOperation::Rebasing => $this->translator->trans('rebase'),
            GitOperation::CherryPicking => $this->translator->trans('cherry-pick'),
            GitOperation::Reverting => $this->translator->trans('revert'),
        };
    }

    /** The operation in progress, or null — said, when there is none. */
    private function inProgress(): ?OperationState
    {
        $state = $this->refresher->operation();
        if (null === $state) {
            $this->notifier->flash($this->translator->trans('No merge or rebase in progress'));
        }

        return $state;
    }

    private function run(GitOperation $operation, OperationStep $step): void
    {
        $this->git->resumeOperation($operation, $step, function (Outcome $ok, string $error) use ($operation, $step): void {
            $ok->isOk()
                ? $this->notifier->success($this->translator->trans(match ($step) {
                    OperationStep::Continue => 'Continued the %operation%',
                    OperationStep::Skip => 'Skipped the step and continued the %operation%',
                    OperationStep::Abort => 'Aborted the %operation%',
                }, ['%operation%' => $this->name($operation)]), 3.0)
                : $this->notifier->error('' === $error ? $this->translator->trans('git refused') : $error, 6.0);
            $this->refresher->refreshBranch();
            $this->dispatcher->dispatch(new WorkingTreeChanged());
        });
    }
}
