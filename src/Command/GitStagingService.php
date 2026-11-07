<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Command;

use Porta\DiffPlugin\DiffDocument;
use Porta\Editor\ConflictMarkers;
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
use Porta\Support\Files;
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
        // Staging a conflicted file is what marks it resolved, and git would
        // take the markers too: not while any is left in it.
        if ($entry->isUnmerged() && self::hasMarkers($abs)) {
            $this->notifier->warn($this->translator->trans('%file% still has conflict markers: resolve them first', ['%file%' => \basename($entry->path)]));

            return;
        }
        $done = $this->afterStaging($onChanged);

        StagedState::Full === $entry->stagedState()
            ? $this->git->unstageFile($abs, $done, null === $entry->renamedFrom ? null : $this->paths->absolute($entry->renamedFrom))
            : $this->git->stage($abs, $done);
    }

    /**
     * Stage every change — the changes list's header checkbox when it is
     * clear or mixed (a mixed state stages, as a browser's indeterminate box
     * checks). Not while a conflicted file still holds a marker: everything
     * staged would mark it resolved.
     *
     * @param \Closure(): void $onChanged re-read status after the index moves
     */
    public function stageAll(\Closure $onChanged): void
    {
        $done = $this->afterStaging($onChanged);
        $this->git->conflictsLeft(function (array $left) use ($done): void {
            if ([] === $left) {
                $this->git->stageAll($done);

                return;
            }
            $this->notifier->warn($this->translator->trans('%file% still has conflict markers: resolve them first', ['%file%' => \basename($left[0])]));
        });
    }

    /**
     * Unstage every change — the header checkbox when it is checked.
     *
     * @param \Closure(): void $onChanged re-read status after the index moves
     */
    public function unstageAll(\Closure $onChanged): void
    {
        $this->git->unstageAll($this->afterStaging($onChanged));
    }

    /**
     * @param \Closure(): void $onChanged
     *
     * @return \Closure(Outcome): void
     */
    private function afterStaging(\Closure $onChanged): \Closure
    {
        return function (Outcome $ok) use ($onChanged): void {
            if (!$ok->isOk()) {
                $this->notifier->error($this->translator->trans('git refused the staging change'));
            }
            $onChanged();
        };
    }

    /** Whether $path is a folder holding a repository of its own: a clone, or a worktree's `.git` file. */
    private static function holdsARepository(string $path): bool
    {
        return \is_dir($path) && \file_exists($path.'/.git');
    }

    /** Why $entry is not discarded at all, or null. */
    private function refusal(ChangeEntry $entry): ?string
    {
        return match (true) {
            // git does not look inside a nested repository: another clone, or a
            // linked worktree under a relative worktreeFolder, is ONE untracked
            // entry — and deleting it took its uncommitted work and its .git.
            $entry->isUntracked() && self::holdsARepository($this->paths->absolute($entry->path)) => $this->translator->trans('%path% is a git repository of its own — not deleted', [
                '%path%' => $entry->path,
            ]),
            // A conflict has no one state to go back to: `AA` would be routed
            // as an added file, and `git rm -f` took both sides with it.
            $entry->isUnmerged() => $this->translator->trans('%path% has a conflict — resolve it, or abort the merge', ['%path%' => $entry->path]),
            default => null,
        };
    }

    /**
     * An untracked file's discard: it is deleted. One the disk will not let go
     * of is said so — "Deleted" over a file still there told the user it was
     * gone. (Already gone — the remove of a missing path succeeds — is gone.)
     *
     * @param \Closure(): void $onChanged
     */
    private function discardUntracked(ChangeEntry $entry, \Closure $onChanged): void
    {
        try {
            new Filesystem()->remove($this->paths->absolute($entry->path));
            $this->notifier->flash($this->translator->trans('Deleted %path%', ['%path%' => $entry->path]));
        } catch (IOExceptionInterface) {
            $this->notifier->error($this->translator->trans('Cannot delete %file%', ['%file%' => $entry->path]));
        }
        // Either way: a folder's remove may have taken part of it before it threw.
        $onChanged();
    }

    /**
     * Discard a file's working-tree changes; an untracked file is deleted.
     *
     * @param \Closure(): void $onChanged re-read status after the file reverts
     */
    public function discard(ChangeEntry $entry, \Closure $onChanged): void
    {
        $refused = $this->refusal($entry);
        if (null !== $refused) {
            $this->notifier->warn($refused);

            return;
        }
        if ($entry->isUntracked()) {
            $this->discardUntracked($entry, $onChanged);

            return;
        }
        $done = function (Outcome $ok) use ($entry, $onChanged): void {
            $this->notifier->flash(
                $ok->isOk()
                    ? $this->translator->trans('Discarded changes to %path%', ['%path%' => $entry->path])
                    : $this->translator->trans('Discard failed'),
            );
            $onChanged();
        };
        $abs = $this->paths->absolute($entry->path);
        match (true) {
            // Only in the worktree: the index has what to go back to.
            ' ' === $entry->indexStatus => $this->git->discardFile($abs, $done),
            // New in the index: there is no HEAD copy; it goes, as an untracked one does.
            'A' === $entry->indexStatus => $this->git->discardAdded($abs, $done),
            // A copy: its source never went anywhere, and is not this row's to touch.
            'C' === $entry->indexStatus => $this->git->discardAdded($abs, $done),
            // A rename: the new name gone, and the old one back.
            null !== $entry->renamedFrom => $this->git->discardAdded($abs, function (Outcome $ok) use ($entry, $done): void {
                $ok->isOk() ? $this->restoreRenamedFrom($this->paths->absolute($entry->renamedFrom), $done) : $done($ok);
            }),
            default => $this->git->discardToHead([$abs], $done),
        };
    }

    /**
     * A discarded rename's old name, back from HEAD — on disk only when
     * nothing is there now. A file written at the old name after the rename
     * is the user's: restored over, it was gone without a word. Then only the
     * index goes back, and the file shows as what it is, a change to it.
     *
     * @param \Closure(Outcome): void $done
     */
    private function restoreRenamedFrom(string $old, \Closure $done): void
    {
        \file_exists($old) ? $this->git->unstageFile($old, $done) : $this->git->discardToHead([$old], $done);
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

    /** Whether the file on disk still holds any of a conflict — a whole block, or a marker one left. */
    private static function hasMarkers(string $abs): bool
    {
        $text = Files::tryRead($abs);

        return null !== $text && ConflictMarkers::unresolved(\explode("\n", $text));
    }
}
