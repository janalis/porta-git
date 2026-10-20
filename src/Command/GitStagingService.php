<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Command;

use Porta\DiffPlugin\DiffDocument;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Diff\DiffSide;
use Porta\GitPlugin\Diff\PatchBuilder;
use Porta\GitPlugin\Diff\WhitespaceMode;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\StagedState;
use Porta\GitPlugin\Support\Flash;
use Porta\GitPlugin\View\GitViewController;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\I18n\Translator;
use Porta\Support\Outcome;
use Porta\Support\ProjectPaths;
use Porta\Support\Text;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The index-mutating half of the git view: stage/unstage a file, stage a
 * line/hunk selection from the diff, discard a file's changes, and commit.
 * Each verb runs the git plumbing, flashes the outcome, and calls back so the
 * {@see GitViewController} can refresh the status and reload whatever diff is
 * shown. Split out of the controller so it owns "change the repo" while the
 * controller owns "swap panes, move focus, display diffs".
 */
final readonly class GitStagingService
{
    public function __construct(
        private GitInfo $git,
        private ProjectPaths $paths,
        private CommitBoxWidget $commitBox,
        private Flash $notifier,
        private Translator $translator,
    ) {}

    /**
     * Stage (or unstage) a line/hunk selection from the diff pane: a worktree
     * diff stages the selection into the index; a cached diff unstages it (the
     * patch is built from the inverted hunks, same recount path). Untracked
     * files have no partial form — the whole file stages from the list.
     *
     * @param array<int, array<int, true>> $selected  hunk index => selected line set
     * @param \Closure(): void              $onApplied refresh + reload the shown diff
     */
    public function stageSelection(ChangeEntry $entry, DiffDocument $document, DiffSide $side, WhitespaceMode $whitespace, array $selected, \Closure $onApplied): void
    {
        $refusal = match (true) {
            DiffSide::Untracked === $side => $this->translator->trans('Untracked: stage the whole file with Space in the changes list'),
            // A -w diff's hunks omit whitespace-only lines, so a patch built
            // from them no longer matches the preimage — git apply would balk.
            WhitespaceMode::Hidden === $whitespace => $this->translator->trans('Show whitespace changes to stage lines (w)'),
            default => null,
        };
        if (null !== $refusal) {
            $this->notifier->flash($refusal);

            return;
        }

        $hunks = DiffSide::Cached === $side
            ? \array_map(PatchBuilder::invertHunk(...), $document->hunks)
            : $document->hunks;
        $patch = PatchBuilder::build($entry->path, $hunks, $selected);
        if (null === $patch) {
            return; // only context rows selected
        }

        $this->git->applyCached($patch, function (Outcome $ok) use ($onApplied): void {
            if (!$ok->isOk()) {
                $this->notifier->error($this->translator->trans('git apply refused the partial patch'));
            }
            $onApplied();
        });
    }

    /**
     * Stage a whole file, or unstage it when it is already fully staged.
     *
     * @param \Closure(): void $onChanged re-read status after the index moves
     */
    public function toggleStage(ChangeEntry $entry, \Closure $onChanged): void
    {
        $abs = $this->paths->absolute($entry->path);
        $done = function (Outcome $ok) use ($onChanged): void {
            if (!$ok->isOk()) {
                $this->notifier->error($this->translator->trans('git refused the staging change'));
            }
            $onChanged();
        };

        StagedState::Full === $entry->stagedState() ? $this->git->unstageFile($abs, $done) : $this->git->stage($abs, $done);
    }

    /**
     * Stage every change, or unstage every one — the changes list's header
     * checkbox, whose mixed state stages, as a browser's indeterminate box
     * checks.
     *
     * @param \Closure(): void $onChanged re-read status after the index moves
     */
    // @mago-expect lint:no-boolean-flag-parameter -- stage or unstage IS the request, the header box's one bit.
    public function stageAll(bool $stage, \Closure $onChanged): void
    {
        $done = function (Outcome $ok) use ($onChanged): void {
            if (!$ok->isOk()) {
                $this->notifier->error($this->translator->trans('git refused the staging change'));
            }
            $onChanged();
        };

        $stage ? $this->git->stageAll($done) : $this->git->unstageAll($done);
    }

    /**
     * Discard a file's working-tree changes; an untracked file is deleted.
     *
     * @param \Closure(): void $onChanged re-read status after the file reverts
     */
    public function discard(ChangeEntry $entry, \Closure $onChanged): void
    {
        if ($entry->isUntracked()) {
            // @mago-expect lint:no-empty-catch-clause -- best-effort delete: a vanished or unremovable file still flashes below
            try {
                new Filesystem()->remove($this->paths->absolute($entry->path));
            } catch (IOExceptionInterface) {
            }
            $this->notifier->flash($this->translator->trans('Deleted %path%', ['%path%' => $entry->path]));
            $onChanged();

            return;
        }
        $this->git->discardFile($this->paths->absolute($entry->path), function (Outcome $ok) use ($entry, $onChanged): void {
            $this->notifier->flash(
                $ok->isOk()
                    ? $this->translator->trans('Discarded changes to %path%', ['%path%' => $entry->path])
                    : $this->translator->trans('Discard failed'),
            );
            $onChanged();
        });
    }

    /**
     * Commit the staged changes with $message, amending HEAD when $mode is
     * {@see CommitMode::Amend}.
     *
     * @param \Closure(): void $onCommitted refresh gutter marks + branch on success
     * @param \Closure(): void $onChanged   re-read status after the commit
     */
    public function commit(string $message, CommitMode $mode, \Closure $onCommitted, \Closure $onChanged): void
    {
        $this->git->commit(
            $message,
            function (Outcome $ok, string $out) use ($onCommitted, $onChanged): void {
                $summary = Text::firstLine($out);
                if ($ok->isOk()) {
                    $this->commitBox->clear();
                    $this->notifier->success($this->translator->trans('Committed: %summary%', ['%summary%' => $summary]));
                    $onCommitted();
                    $onChanged();

                    return;
                }
                $this->notifier->error($this->translator->trans('Commit failed: %summary%', ['%summary%' => $summary]));
                $onChanged();
            },
            $mode,
        );
    }
}
