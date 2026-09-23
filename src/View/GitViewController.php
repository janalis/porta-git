<?php

declare(strict_types=1);

namespace Porta\GitPlugin\View;

use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Command\CommitMode;
use Porta\GitPlugin\Command\GitRemoteCommands;
use Porta\GitPlugin\Command\GitStagingService;
use Porta\GitPlugin\Command\HistoryReload;
use Porta\GitPlugin\Command\RefreshMode;
use Porta\GitPlugin\Diff\DiffDocument;
use Porta\GitPlugin\Diff\DiffSide;
use Porta\GitPlugin\Diff\WhitespaceMode;
use Porta\GitPlugin\Event\GitChangeDiscardRequested;
use Porta\GitPlugin\Event\GitChangeSelected;
use Porta\GitPlugin\Event\GitChangeStageToggleRequested;
use Porta\GitPlugin\Event\GitCommitFileSelected;
use Porta\GitPlugin\Event\GitCommitSelected;
use Porta\GitPlugin\Event\GitDiffContextCycleRequested;
use Porta\GitPlugin\Event\GitDiffCopyRequested;
use Porta\GitPlugin\Event\GitDiffLayoutToggled;
use Porta\GitPlugin\Event\GitDiffStagingRequested;
use Porta\GitPlugin\Event\GitDiffWhitespaceToggleRequested;
use Porta\GitPlugin\Event\GitFileHistoryRequested;
use Porta\GitPlugin\Event\GitHistoryEndReached;
use Porta\GitPlugin\Event\GitRemoteRequested;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Setting\DiffPrefs;
use Porta\GitPlugin\Setting\GitSettings;
use Porta\GitPlugin\StagedState;
use Porta\GitPlugin\Support\Flash;
use Porta\I18n\Translator;
use Porta\Layout\DiffLayout;
use Porta\Layout\ViewRegion;
use Porta\Support\ProjectPaths;
use Porta\Workbench\Menu\ViewMenuSource;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewHost;
use Porta\Workbench\View\ViewPointer;
use Psr\EventDispatcher\EventDispatcherInterface;
use Revolt\EventLoop;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

/**
 * The GitHub-Desktop-style git view: swaps the main area's tree|editor
 * children for [changes list + commit box | diff], keeps the changes model
 * fresh (on every mutation plus a 2s poll while open), and runs the
 * stage/unstage/discard/commit pipelines against {@see GitInfo}.
 */
final class GitViewController implements ContributedView
{
    private bool $open = false;

    /**
     * Whether a `git status` is already running. The quiet poll skips its tick
     * instead of stacking another process — {@see \Porta\GitPlugin\Process\ProcessLane}
     * is a cancellation scope, not a queue, so on a large repository a status
     * slower than the interval would pile up subprocesses unbounded. A close()
     * cancelling a status mid-flight (its answer never comes) cannot wedge the
     * guard: only the QUIET poll honors it, and open()'s eager refresh always
     * answers and clears it before the next poll is armed.
     */
    private bool $statusInFlight = false;
    /**
     * Issue order for `git status` and for diff loads, so a superseded answer
     * can be dropped.
     *
     * Both overlap in normal use — a staging completion refreshes while the
     * quiet poll's status is still out, and that refresh's callback reloads
     * the diff while a direct reload for the same path is in flight. Their
     * answers come back in ARRIVAL order, which under load is not issue
     * order, and last-writer-wins by arrival republishes the older snapshot.
     * For status that means a pre-staging entry list; the reload it then
     * drives picks its side from that entry's `worktreeStatus`, asks for the
     * worktree diff of a file that is now fully staged, gets nothing back and
     * blanks the pane. `shownDocument` is null from there on, so the next
     * space in the diff pane silently no-ops.
     *
     * A counter rather than {@see $statusInFlight}: "something is running" is
     * a different fact from "this answer is still the newest one", and only
     * the second can order two answers that are both already back.
     */
    private int $statusGeneration = 0;
    private int $diffGeneration = 0;
    /** @var list<ChangeEntry> */
    private array $entries = [];
    private string $lastStatusRaw = "\0unset";
    private ?string $poll = null;
    /** The entry, parsed document and index side shown in the diff pane. */
    private ?ChangeEntry $shownEntry = null;
    private ?DiffDocument $shownDocument = null;
    private DiffSide $shownSide = DiffSide::Worktree;
    private GitViewMode $mode = GitViewMode::Changes;
    /** Diff pane display toggle (persisted): drop whitespace-only changes (-w). */
    private bool $hideWhitespace = false;
    /** The commit + path shown in the history diff, for whitespace-toggle reloads. */
    private ?LogEntry $shownCommit = null;
    private ?string $shownCommitPath = null;

    /** Diff context cycle (`c` in the diff pane): git's default → ±10 → the whole file. */
    private const array CONTEXT_STEPS = [null, 10, 999_999];
    private const array CONTEXT_LABELS = ['default', '±10 lines', 'whole file'];

    private int $contextStep = 0;

    /** Runs the index-mutating verbs (stage/unstage/discard/commit) for this view. */
    private readonly GitStagingService $staging;

    private readonly ViewRegion $region;

    public function __construct(
        private readonly GitInfo $git,
        private readonly ProjectPaths $paths,
        private readonly GitPanes $panes,
        ViewRegion $region,
        private readonly ViewHost $host,
        private readonly GitMouseRouter $mouse,
        private readonly GitContextMenus $menus,
        private readonly Flash $notifier,
        private readonly Translator $translator,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly DiffPrefs $prefs,
        /** @var \Closure(string): void copies to the system clipboard */
        private readonly \Closure $copy,
        private readonly ?GitRemoteCommands $remoteCommands = null,
        private readonly int $historyPageSize = 200,
        private readonly ?GitSettings $gitSettings = null,
    ) {
        // The region arrives already minted, on ViewServices: core mints it for
        // the id this view registered under, BEFORE calling the factory, so
        // both panes can be filled here. The sidebar is refilled per mode; the
        // diff pane is fixed, so it goes in once.
        $this->region = $region;
        $this->region->content()->add($this->panes->diffView);
        // How this sidebar resizes. Four stacked widgets each render their own
        // width, so a drag fans the new one out to all of them; core sees a
        // container and cannot know that.
        $this->region->setSidebarPanes(new GitSidebar($this->panes->tabs, $this->panes->changes, $this->panes->commitBox, $this->panes->history));
        // Seeded for the initial Changes mode. WorkspaceLayout used to stack
        // these three at construction — the one place core layout named a
        // view's contents — and switchMode() only refills on a CHANGE, so
        // without this the sidebar would open empty.
        $this->region->sidebar()->add($this->panes->tabs)->add($this->panes->changes)->add($this->panes->commitBox);
        $this->staging = new GitStagingService($this->git, $this->paths, $this->panes->commitBox, $this->notifier, $this->translator);

        $exit = $this->close(...);
        $next = $this->focusNext(...);

        $this->panes->changes->onFocusNext($next)->onExit($exit);
        $this->panes->history->onFocusNext($next)->onExit($exit);
        $this->panes->diffView->onFocusNext($next)->onExit($exit);
        $this->panes
            ->commitBox
            ->setStagedChangesProvider($this->hasStagedChanges(...))
            ->onCommit(fn (string $message) => $this->commit($message, $this->panes->commitBox->isAmend() ? CommitMode::Amend : CommitMode::Fresh))
            ->onAmendToggle(fn () => $this->amendToggled($this->panes->commitBox->isAmend() ? CommitMode::Amend : CommitMode::Fresh))
            ->onFocusNext($next)
            ->onExit($exit);
    }

    public function viewId(): string
    {
        return 'git';
    }

    public function ownsMainArea(): bool
    {
        return true;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /** Where focus should land when a popup (e.g. the app menu) closes over the view. */
    public function pointer(): ?ViewPointer
    {
        return $this->mouse;
    }

    public function contextMenus(): ?ViewMenuSource
    {
        return $this->menus;
    }

    public function focusTarget(): AbstractWidget&FocusableInterface
    {
        return GitViewMode::History === $this->mode ? $this->panes->history : $this->panes->changes;
    }

    public function toggle(): void
    {
        $this->open ? $this->close() : $this->open();
    }

    public function open(): void
    {
        if ($this->open) {
            return;
        }
        if (!$this->git->isAvailable()) {
            $this->notifier->flash($this->translator->trans('Not a git repository'));

            return;
        }

        $this->open = true;
        // The (global, full-width) menu bar stays put; just drop any open
        // dropdown so its tracked hit region cannot go stale across the swap.
        $this->host->show($this->region);
        if (GitViewMode::Changes !== $this->mode) {
            $this->switchMode(GitViewMode::Changes); // the view always opens on Changes
        }
        $this->host->focus($this->panes->changes);
        $this->lastStatusRaw = "\0unset";
        $this->contextStep = 0;
        $prefs = $this->prefs->read();
        DiffLayout::Split === $prefs['layout'] ? $this->panes->diffView->useSplitView() : $this->panes->diffView->useUnifiedView();
        $this->hideWhitespace = $prefs['hideWhitespace'];
        $this->hideWhitespace ? $this->panes->diffView->hideWhitespace() : $this->panes->diffView->showWhitespace();
        $this->refresh();
        $this->armPoll();
        $this->host->invalidate();
    }

    /**
     * (Re-)arm the status poll at the interval currently configured.
     *
     * Also the settings hook's entry point: the interval is baked into an
     * `EventLoop::repeat` when the view opens, so editing it with the view up
     * would otherwise do nothing until the view was closed and reopened.
     * A no-op while closed — {@see open()} will arm it with the new value.
     */
    public function armPoll(): void
    {
        if (!$this->open) {
            return;
        }
        if (null !== $this->poll) {
            EventLoop::cancel($this->poll);
        }
        $interval = (float) ($this->gitSettings->refreshInterval ?? 2);
        $this->poll = EventLoop::repeat($interval, fn () => $this->refresh(RefreshMode::Quiet));
    }

    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        if (null !== $this->poll) {
            EventLoop::cancel($this->poll);
            $this->poll = null;
        }
        $this->host->restore();
    }

    /** Re-read `git status`; {@see RefreshMode::Quiet} skips the repaint when nothing changed. */
    public function refresh(RefreshMode $mode = RefreshMode::Eager): void
    {
        if (RefreshMode::Quiet === $mode && $this->statusInFlight) {
            return; // this tick's answer is already on its way
        }
        $this->statusInFlight = true;
        $generation = ++$this->statusGeneration;
        if (RefreshMode::Eager === $mode) {
            // Not per poll: a branch gains its first commit through a commit,
            // and a commit refreshes eagerly.
            $this->git->hasHead(function (bool $head): void {
                $this->panes->commitBox->setAmendable($head);
            });
        }
        $this->git->status(function (array $entries, string $raw) use ($mode, $generation): void {
            if ($generation !== $this->statusGeneration) {
                return; // a newer status is out or already applied; see $statusGeneration
            }
            $this->statusInFlight = false;
            if (!$this->open || RefreshMode::Quiet === $mode && $raw === $this->lastStatusRaw) {
                return;
            }
            $this->lastStatusRaw = $raw;
            $this->entries = $entries;
            $this->panes->changes->setEntries($entries);
            $this->panes->commitBox->invalidate(); // the button's enabled state may flip

            if (GitViewMode::History === $this->mode) {
                return; // the diff pane belongs to the commit browser right now
            }
            $selected = $this->panes->changes->selectedEntry();
            if (null === $selected) {
                $this->panes->diffView->setDocument(null, null, 'Working tree clean');
                $this->host->invalidate();

                return;
            }
            // Always reload: the same path's diff side can flip between
            // worktree and cached as staging changes; a same-path reload
            // keeps the diff cursor in place.
            $this->loadDiff($selected);
            $this->host->invalidate();
        });
    }

    /**
     * Load the diff the user can act on: the worktree side while it is dirty,
     * else the staged side; untracked files diff against /dev/null.
     */
    private function loadDiff(ChangeEntry $entry): void
    {
        $abs = $this->paths->absolute($entry->path);
        $generation = ++$this->diffGeneration;
        $show = function (string $raw) use ($entry, $generation): void {
            if (!$this->open || $generation !== $this->diffGeneration) {
                return; // superseded by a later load; see $statusGeneration
            }
            $document = DiffDocument::parse($raw);
            $this->shownEntry = $entry;
            $this->shownDocument = $document->isEmpty() ? null : $document;
            $this->panes->diffView->setDocument($this->shownDocument, $entry->path, $this->hideWhitespace ? 'No changes (whitespace hidden)' : 'No changes');
            $this->host->invalidate();
        };

        if ($entry->isUntracked()) {
            $this->shownSide = DiffSide::Untracked;
            $this->git->untrackedFileDiff($abs, $show);

            return;
        }
        if (' ' !== $entry->worktreeStatus) {
            $this->shownSide = DiffSide::Worktree;
            $this->git->worktreeFileDiff($abs, $show, self::CONTEXT_STEPS[$this->contextStep], $this->hideWhitespace ? WhitespaceMode::Hidden : WhitespaceMode::Shown);

            return;
        }
        $this->shownSide = DiffSide::Cached;
        $this->git->cachedFileDiff($abs, $show, self::CONTEXT_STEPS[$this->contextStep], $this->hideWhitespace ? WhitespaceMode::Hidden : WhitespaceMode::Shown);
    }

    /** ctrl+c in the diff pane: selected plain-code lines onto the clipboard. */
    private function copyDiffText(string $text): void
    {
        ($this->copy)($text);
        $lines = \substr_count(\rtrim($text, characters: "\n"), needle: "\n") + 1;
        $this->notifier->flash($this->translator->trans('Copied %count% line|Copied %count% lines', ['%count%' => $lines]));
    }

    /** Persist the diff pane's current split/whitespace display state. */
    private function persistDiffPrefs(): void
    {
        $this->prefs->write(
            $this->panes->diffView->isSplit() ? DiffLayout::Split : DiffLayout::Unified,
            $this->hideWhitespace,
        );
    }

    /**
     * `w` on the diff toolbar: flip whitespace hiding, persist, and reload
     * whichever diff is shown (the changes-side file or the history patch).
     */
    private function toggleWhitespace(): void
    {
        $this->hideWhitespace = !$this->hideWhitespace;
        $this->hideWhitespace ? $this->panes->diffView->hideWhitespace() : $this->panes->diffView->showWhitespace();
        $this->persistDiffPrefs();
        $this->notifier->flash($this->translator->trans($this->hideWhitespace ? 'Whitespace changes hidden' : 'Whitespace changes shown'));

        if (GitViewMode::History === $this->mode) {
            if (null !== $this->shownCommit && null !== $this->shownCommitPath) {
                $this->loadCommitFileDiff($this->shownCommit, $this->shownCommitPath);
            }
            $this->host->invalidate();

            return;
        }
        if (null !== $this->shownEntry) {
            $this->loadDiff($this->shownEntry);
        }
        $this->host->invalidate();
    }

    /** `c` in the diff pane: reload with the next context size in the cycle. */
    private function cycleContext(): void
    {
        if (null === $this->shownEntry) {
            return;
        }
        if (DiffSide::Untracked === $this->shownSide) {
            $this->notifier->flash($this->translator->trans('Untracked files always show the whole file'));

            return;
        }
        $this->contextStep = ($this->contextStep + 1) % \count(self::CONTEXT_STEPS);
        $this->notifier->flash($this->translator->trans('Diff context: %label%', ['%label%' => $this->translator->trans(self::CONTEXT_LABELS[$this->contextStep])]));
        $this->loadDiff($this->shownEntry);
    }

    /**
     * Line/hunk staging from the diff pane, delegated to the staging service
     * once the currently-shown diff is known; the callback refreshes and
     * reloads the same path so the diff view keeps its cursor.
     *
     * @param array<int, array<int, true>> $selected hunk index => selected line set
     */
    private function stageSelection(array $selected): void
    {
        if (null === $this->shownEntry || null === $this->shownDocument) {
            return;
        }
        $entry = $this->shownEntry;
        $this->staging->stageSelection($entry, $this->shownDocument, $this->shownSide, $this->hideWhitespace ? WhitespaceMode::Hidden : WhitespaceMode::Shown, $selected, function () use (
            $entry,
        ): void {
            $this->refresh();
            $this->loadDiff($entry); // same path: the diff view keeps its cursor
        });
    }

    private function toggleStage(ChangeEntry $entry): void
    {
        $this->staging->toggleStage($entry, $this->refresh(...));
    }

    private function discard(ChangeEntry $entry): void
    {
        $this->staging->discard($entry, $this->refresh(...));
    }

    /** Amend armed: prefill the last commit's message; disarmed: start fresh. */
    private function amendToggled(CommitMode $mode): void
    {
        if (CommitMode::Amend !== $mode) {
            $this->panes->commitBox->setMessage('');
            $this->host->invalidate();

            return;
        }
        $this->git->lastCommitMessage(function (string $message): void {
            if ($this->open && $this->panes->commitBox->isAmend()) {
                $this->panes->commitBox->setMessage($message);
            }
            $this->host->invalidate();
        });
    }

    private function commit(string $message, CommitMode $mode = CommitMode::Fresh): void
    {
        $this->staging->commit($message, $mode, fn () => $this->dispatcher->dispatch(new GitCommitted()), $this->refresh(...));
    }

    /** Swap the left pane between the Changes and History tabs. */
    private function switchMode(GitViewMode $mode): void
    {
        if ($mode === $this->mode) {
            return;
        }
        $this->mode = $mode;
        $this->panes->tabs->setActive($mode);
        $this->region->sidebar()->clear();
        $this->region->sidebar()->add($this->panes->tabs);
        if (GitViewMode::History === $mode) {
            $this->region->sidebar()->add($this->panes->history);
            $this->panes->diffView->makeReadOnly();
            $this->panes->diffView->setDocument(null, null, 'Select a commit file');
            $this->host->focus($this->panes->history);
            $this->reloadHistory(HistoryReload::SelectFirst);
            $this->host->invalidate();

            return;
        }
        // Tabbing away drops any file filter — History reopens repo-wide.
        $this->historyPath = null;
        $this->panes->history->setFilterLabel(null);
        $this->region->sidebar()->add($this->panes->changes);
        $this->region->sidebar()->add($this->panes->commitBox);
        $this->panes->diffView->makeEditable();
        $this->host->focus($this->panes->changes);
        $this->refresh();
        $this->host->invalidate();
    }

    /** The repo-relative file the History tab is filtered to, or null for repo-wide. */
    private ?string $historyPath = null;

    /** Reload history page 0 (honoring the file filter), optionally loading the first commit's files. */
    private function reloadHistory(HistoryReload $mode): void
    {
        $this->git->log(
            $this->historyPageSize,
            function (array $commits) use ($mode): void {
                if (!$this->open || GitViewMode::History !== $this->mode) {
                    return;
                }
                // Read before the reset: setCommits() rebuilds the list from
                // scratch, so KeepSelection has to name what to come back to.
                $keep = HistoryReload::KeepSelection === $mode ? $this->panes->history->selectedCommit()?->sha : null;
                $this->panes->history->setCommits($commits);
                \count($commits) === $this->historyPageSize ? $this->panes->history->markHasMore() : $this->panes->history->markNoMore();
                if (null !== $keep) {
                    // Gone after a rebase or a force push: the first commit is
                    // the only honest fallback, and it is where the reset left
                    // the selection anyway.
                    $this->panes->history->selectSha($keep);
                }
                // Whatever ends up selected, its files were just cleared — and
                // the patch in the diff pane belongs to the commit that WAS
                // selected. Reload, always: a fetch used to jump the list to the
                // top while the pane kept showing the old commit's diff, with
                // nothing on screen saying the two no longer went together.
                $selected = $this->panes->history->selectedCommit();
                if (null === $selected) {
                    // Filtering to a file with no history at all: say so, or
                    // the pane keeps the last patch it was given.
                    $this->panes->diffView->setDocument(null, null, 'No commits');
                    $this->host->invalidate();

                    return;
                }
                $this->loadCommitFiles($selected);
                $this->host->invalidate();
            },
            path: $this->historyPath,
        );
    }

    /**
     * Open the git view on the History tab filtered to one file — the
     * "commits that touched this file" view. The filter clears when the
     * user tabs back to Changes.
     */
    public function onFileHistoryRequested(GitFileHistoryRequested $event): void
    {
        $this->openFileHistory($event->path);
    }

    public function openFileHistory(string $absPath): void
    {
        $this->open();
        if (!$this->open) {
            return; // not a repository — open() already flashed
        }
        $this->historyPath = $this->paths->relative($absPath);
        $this->panes->history->setFilterLabel($this->historyPath);
        if (GitViewMode::History === $this->mode) {
            $this->reloadHistory(HistoryReload::SelectFirst);

            return;
        }
        $this->switchMode(GitViewMode::History);
    }

    /**
     * After a fetch/pull/push: the history tab shows stale commits — reload
     * page 0 (the changes list self-heals via its poll).
     */
    public function onChangeSelected(GitChangeSelected $event): void
    {
        $this->loadDiff($event->entry);
    }

    public function onChangeStageToggled(GitChangeStageToggleRequested $event): void
    {
        $this->toggleStage($event->entry);
    }

    public function onChangeDiscarded(GitChangeDiscardRequested $event): void
    {
        $this->discard($event->entry);
    }

    public function onCommitSelected(GitCommitSelected $event): void
    {
        $this->loadCommitFiles($event->commit);
    }

    public function onCommitFileSelected(GitCommitFileSelected $event): void
    {
        $this->loadCommitFileDiff($event->commit, $event->path);
    }

    public function onHistoryEndReached(GitHistoryEndReached $event): void
    {
        $this->loadMoreHistory();
    }

    public function onDiffLinesStaged(GitDiffStagingRequested $event): void
    {
        $this->stageSelection($event->selection);
    }

    public function onDiffContextCycled(GitDiffContextCycleRequested $event): void
    {
        $this->cycleContext();
    }

    public function onDiffLayoutToggled(GitDiffLayoutToggled $event): void
    {
        $this->persistDiffPrefs();
    }

    public function onDiffWhitespaceToggled(GitDiffWhitespaceToggleRequested $event): void
    {
        $this->toggleWhitespace();
    }

    public function onDiffTextCopied(GitDiffCopyRequested $event): void
    {
        $this->copyDiffText($event->text);
    }

    /** fetch / pull / push, from either git list pane. */
    public function onRemoteRequested(GitRemoteRequested $event): void
    {
        $this->remoteCommands?->runOperation($event->operation);
    }

    /**
     * Changes or History — one listener for what the two panes' keys and the
     * tab strip all asked for separately.
     */
    public function onViewModeRequested(GitViewModeRequested $event): void
    {
        $this->switchMode($event->mode);
    }

    public function onRemoteCompleted(GitRemoteCompleted $event): void
    {
        if ($this->open && GitViewMode::History === $this->mode) {
            $this->reloadHistory(HistoryReload::KeepSelection);
        }
    }

    /** Pull the next page of history under the loaded commits. */
    private function loadMoreHistory(): void
    {
        $this->git->log(
            $this->historyPageSize,
            function (array $commits): void {
                if (!$this->open || GitViewMode::History !== $this->mode) {
                    return;
                }
                $this->panes->history->appendCommits($commits);
                \count($commits) === $this->historyPageSize ? $this->panes->history->markHasMore() : $this->panes->history->markNoMore();
                $this->host->invalidate();
            },
            skip: $this->panes->history->commitCount(),
            path: $this->historyPath,
        );
    }

    private function loadCommitFiles(LogEntry $commit): void
    {
        $this->git->commitFiles($commit->sha, function (array $files) use ($commit): void {
            if (!$this->open || GitViewMode::History !== $this->mode || $commit->sha !== $this->panes->history->selectedCommit()?->sha) {
                return;
            }
            $this->panes->history->setFiles($files);
            if ([] === $files) {
                $this->panes->diffView->setDocument(null, null, 'No files in this commit');
                $this->host->invalidate();

                return;
            }
            // The first file is selected by default — show its patch right away.
            $this->loadCommitFileDiff($commit, $files[0]['path']);
            $this->host->invalidate();
        });
    }

    private function loadCommitFileDiff(LogEntry $commit, string $path): void
    {
        $this->shownCommit = $commit;
        $this->shownCommitPath = $path;
        $this->git->commitFileDiff(
            $commit->sha,
            $path,
            function (string $raw) use ($path): void {
                if (!$this->open || GitViewMode::History !== $this->mode) {
                    return;
                }
                $document = DiffDocument::parse($raw);
                $this->panes->diffView->setDocument($document->isEmpty() ? null : $document, $path, $this->hideWhitespace ? 'No patch (whitespace hidden)' : 'No patch for this file');
                $this->host->invalidate();
            },
            $this->hideWhitespace ? WhitespaceMode::Hidden : WhitespaceMode::Shown,
        );
    }

    private function hasStagedChanges(): bool
    {
        return \array_any($this->entries, static fn (ChangeEntry $entry): bool => StagedState::None !== $entry->stagedState());
    }

    /** Tab order: changes list → diff → commit box → changes list (history: list ↔ diff). */
    public function focusNext(): void
    {
        $this->cycleFocus(1);
    }

    public function cycleFocus(int $direction): bool
    {
        /** @var list<FocusableInterface&AbstractWidget> $order */
        $order = GitViewMode::History === $this->mode
            ? [$this->panes->history, $this->panes->diffView]
            : [$this->panes->changes, $this->panes->diffView, $this->panes->commitBox];
        $count = \count($order);
        $index = \array_search($this->host->focused(), $order, strict: true);
        $target = $order[false === $index ? 0 : ($index + $direction + $count) % $count];
        if ($target === $this->panes->commitBox) {
            $this->panes->commitBox->resetField();
        }
        $this->host->focus($target);
        $this->host->invalidate();

        return true;
    }
}
