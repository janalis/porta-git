<?php

declare(strict_types=1);

namespace Porta\GitPlugin\View;

use Porta\Event\GitCommitted;
use Porta\Event\GitRemoteCompleted;
use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Command\CommitMode;
use Porta\GitPlugin\Command\GitRemoteCommands;
use Porta\GitPlugin\Command\GitStagingService;
use Porta\GitPlugin\Command\HistoryReload;
use Porta\GitPlugin\Command\RefreshMode;
use Porta\GitPlugin\Diff\DiffDocument;
use Porta\GitPlugin\Diff\DiffSide;
use Porta\GitPlugin\Diff\DirectoryComparison;
use Porta\GitPlugin\Diff\WhitespaceMode;
use Porta\GitPlugin\DiffStat;
use Porta\GitPlugin\Event\GitChangeDiscardRequested;
use Porta\GitPlugin\Event\GitChangeSelected;
use Porta\GitPlugin\Event\GitChangeStageToggleRequested;
use Porta\GitPlugin\Event\GitCommitFileSelected;
use Porta\GitPlugin\Event\GitCommitHashCopyRequested;
use Porta\GitPlugin\Event\GitCommitSelected;
use Porta\GitPlugin\Event\GitDiffContextCycleRequested;
use Porta\GitPlugin\Event\GitDiffCopyRequested;
use Porta\GitPlugin\Event\GitDiffLayoutToggled;
use Porta\GitPlugin\Event\GitDiffSettingsRequested;
use Porta\GitPlugin\Event\GitDiffStagingRequested;
use Porta\GitPlugin\Event\GitDiffWhitespaceToggleRequested;
use Porta\GitPlugin\Event\GitFileHistoryRequested;
use Porta\GitPlugin\Event\GitHistoryEndReached;
use Porta\GitPlugin\Event\GitRemoteRequested;
use Porta\GitPlugin\Event\GitStageAllRequested;
use Porta\GitPlugin\Event\GitToolbarMenuRequested;
use Porta\GitPlugin\Event\GitViewModeRequested;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\RefreshScope;
use Porta\GitPlugin\RepositoryState;
use Porta\GitPlugin\Setting\DiffPrefs;
use Porta\GitPlugin\Setting\GitSettings;
use Porta\GitPlugin\StagedState;
use Porta\GitPlugin\Support\Flash;
use Porta\GitPlugin\Widget\SplitShape;
use Porta\I18n\Translator;
use Porta\Layout\DiffLayout;
use Porta\Layout\NarrowSplit;
use Porta\Layout\PaneSplit;
use Porta\Layout\ViewRegion;
use Porta\Support\ProjectPaths;
use Porta\Workbench\Menu\MenuItem;
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

    /** Poll ticks between two toolbar re-reads: about ten seconds at the default interval. */
    private const int REREAD_TICKS = 5;

    private int $ticks = 0;

    /** Runs the index-mutating verbs (stage/unstage/discard/commit) for this view. */
    private readonly GitStagingService $staging;

    private readonly ViewRegion $region;

    /**
     * History's files column beside the diff, the divider between them core's
     * to drag and keep ({@see ViewRegion::split()}); stacked when the window
     * is too narrow for the two.
     */
    private readonly PaneSplit $split;

    /** The files column's least columns, and its share of the content before any drag. */
    private const int FILES_MIN = 28;
    private const int FILES_SHARE = 400;

    /** The sidebar as one resizable unit — and how wide it draws, for the toolbar over it. */
    private readonly GitSidebar $sidebar;

    /** The timer turning the remote icon while an operation runs; null at rest. */
    private ?string $spinner = null;

    /** Seconds between two steps of the remote icon's turn. */
    private const float SPIN_SECONDS = 0.12;

    /**
     * Set while the view shows a comparison git difftool asked for: called when
     * it closes, which ends the tool session. Null in the view's own use.
     *
     * @var (\Closure(): void)|null
     */
    private ?\Closure $onComparisonClosed = null;

    /**
     * The two directories of a `git difftool --dir-diff` comparison, whose
     * changed files fill the changes list; null for a two-file one.
     *
     * @var array{string, string}|null
     */
    private ?array $comparedDirs = null;

    /** The repo-relative path to select when the next status lands — a mergetool launch. */
    private ?string $selectOnLoad = null;

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
        private readonly ?RepositoryState $repository = null,
    ) {
        // The region arrives already minted, on ViewServices: core mints it for
        // the id this view registered under, BEFORE calling the factory, so
        // both panes can be filled here. The sidebar is refilled per mode; the
        // diff pane is fixed, so it goes in once.
        $this->region = $region;
        $this->region->content()->add($this->panes->diffView);
        // GitHub Desktop's toolbar spans the window, flush under its title bar.
        $this->region->header()->add($this->panes->toolbar);
        // The toolbar PULLS from the repository state, so an answer has to
        // mark it stale itself: a frame alone repaints its cached first,
        // empty render.
        $this->repository?->onChange(function (): void {
            $this->panes->toolbar->invalidate();
            $this->host->invalidate();
        });
        // GitHub Desktop's middle column, in History only: two fifths of the
        // content to start with, the diff the rest, and a divider to move.
        $this->split = $this->region->split('columns', NarrowSplit::Stacked, self::FILES_SHARE, self::FILES_MIN);
        $this->split->onNarrow($this->stack(...));
        // How this sidebar resizes. Four stacked widgets each render their own
        // width, so a drag fans the new one out to all of them; core sees a
        // container and cannot know that.
        $this->sidebar = new GitSidebar($this->panes->tabs, $this->panes->changes, $this->panes->commitBox, $this->panes->history);
        $this->region->setSidebarPanes($this->sidebar);
        $this->panes->toolbar->followPanes($this->columns(...));
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
        $this->panes
            ->details
            ->onFocusNext($next)
            ->onExit($exit)
            ->onBack(fn () => $this->host->focus($this->panes->history));
        $this->panes->diffView->onFocusNext($next)->onExit($exit);
        // The mode tabs head the sidebar: their lid is heavy while its lists
        // or the commit box hold the keys.
        $this->panes->tabs->setFocusProbe(fn (): bool => \in_array($this->host->focused(), [$this->panes->changes, $this->panes->history, $this->panes->commitBox], strict: true));
        $this->panes
            ->commitBox
            ->setStagedChangesProvider($this->hasStagedChanges(...))
            ->onCommit(fn (string $message) => $this->commit($message, $this->panes->commitBox->isAmend() ? CommitMode::Amend : CommitMode::Fresh))
            ->onAmendToggle(fn () => $this->amendToggled($this->panes->commitBox->isAmend() ? CommitMode::Amend : CommitMode::Fresh))
            ->onFocusNext($next)
            ->onExit($exit);
    }

    /**
     * The panes' widths under a header row $width wide, left to right: the
     * sidebar, then the content — what the toolbar's cells stand over.
     *
     * @return list<int>
     */
    private function columns(int $width): array
    {
        $sidebar = \min($this->sidebar->width(), \max(1, $width - 2));
        $content = $width - $sidebar - 1;
        if (GitViewMode::History !== $this->mode || $this->split->isNarrow()) {
            return [$sidebar, $content];
        }
        $files = $this->split->leadColumns($content);

        return [$sidebar, $files, $content - $files - 1];
    }

    /**
     * Too narrow for three columns, History's files go over the diff and the
     * commit header's rule counts them; with room again, they stand beside it.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- PaneSplit::onNarrow()'s listener: the bool is the one fact it hears.
    private function stack(bool $narrow): void
    {
        $this->panes->details->stack($narrow ? SplitShape::Stacked : SplitShape::Beside);
        $this->panes->header->nameFiles($narrow ? $this->panes->details->fileCount() : null);
        $this->panes->toolbar->invalidate();
        $this->host->invalidate();
    }

    public function viewId(): string
    {
        return 'git';
    }

    public function hostsWindow(): bool
    {
        return true;
    }

    public function title(): string
    {
        return $this->translator->trans('Git');
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
        // The toolbar is the repository's: back after a comparison took it down.
        if ([] === $this->region->header()->all()) {
            $this->region->header()->add($this->panes->toolbar);
        }
        // The (global, full-width) menu bar stays put; just drop any open
        // dropdown so its tracked hit region cannot go stale across the swap.
        $this->host->show($this->region, $this->viewId());
        if (GitViewMode::Changes !== $this->mode) {
            $this->switchMode(GitViewMode::Changes); // the view always opens on Changes
        }
        $this->host->focus($this->panes->changes);
        $this->lastStatusRaw = "\0unset";
        $this->contextStep = 0;
        $this->applyPrefs();
        $this->refresh();
        $this->repository?->refresh();
        $this->armPoll();
        $this->spinWhileRunning();
        $this->host->invalidate();
    }

    /**
     * Open on $absPath's row once the status lands — git mergetool's `$MERGED`,
     * the conflicted file, with its diff in the pane.
     */
    public function openOn(string $absPath): void
    {
        $this->selectOnLoad = $this->paths->relative($absPath);
        $this->open();
    }

    /**
     * Show two ARBITRARY files side by side (git difftool's `$LOCAL`/`$REMOTE`,
     * usually temp blobs outside any repository): the diff pane, read-only,
     * fed by `git diff --no-index`, which needs a git binary and no repository.
     * The status poll never runs — nothing here is the working tree — and
     * closing the view calls $onClosed, which ends the tool session.
     *
     * @param \Closure(): void $onClosed
     */
    public function openComparison(string $left, string $right, \Closure $onClosed): void
    {
        $this->forgetInFlight();
        $this->onComparisonClosed = $onClosed;
        $this->open = true;
        // Two files, likely outside any repository: no branch, nothing to
        // fetch or push, so no toolbar offering to.
        $this->region->header()->clear();
        $this->host->show($this->region, $this->viewId());
        if (GitViewMode::Changes !== $this->mode) {
            $this->switchMode(GitViewMode::Changes);
        }
        // Nothing the repository's status said stays: the commit button reads
        // $entries and line staging needs a shown repository diff, so both are
        // off for the whole comparison.
        $this->entries = [];
        $this->shownEntry = null;
        $this->shownDocument = null;
        $this->panes->changes->setEntries([]);
        $this->applyPrefs();
        $this->panes->diffView->makeReadOnly();
        if (\is_dir($left) && \is_dir($right)) {
            // --dir-diff: the files that differ fill the changes list, and the
            // selected one is compared — through its symlink, to its content.
            $this->comparedDirs = [$left, $right];
            $this->panes->changes->setEntries(DirectoryComparison::changes($left, $right));
            $this->host->focus($this->panes->changes);
            $first = $this->panes->changes->selectedEntry();
            null === $first ? $this->panes->diffView->setDocument(null, null, 'No changes') : $this->compareEntry($first);
            $this->host->invalidate();

            return;
        }
        $this->host->focus($this->panes->diffView);
        $relative = $this->paths->relative($right);
        $this->showComparison($left, $right, \str_starts_with($relative, '/') ? \basename($right) : $relative);
        $this->host->invalidate();
    }

    /** One file of a directory comparison, its two sides resolved to real files. */
    private function compareEntry(ChangeEntry $entry): void
    {
        \assert(null !== $this->comparedDirs, description: 'only a directory comparison lists its files');
        [$left, $right] = $this->comparedDirs;
        $this->showComparison(DirectoryComparison::side($left, $entry->path), DirectoryComparison::side($right, $entry->path), $entry->path);
    }

    /** `git diff --no-index` of two files into the diff pane, titled $title. */
    private function showComparison(string $left, string $right, string $title): void
    {
        $this->git->compareFiles($left, $right, function (string $raw) use ($title): void {
            if (null === $this->onComparisonClosed) {
                return; // closed before git answered
            }
            $document = DiffDocument::parse($raw);
            $this->panes->diffView->setDocument($document->isEmpty() ? null : $document, $title, 'The two files are identical');
            $this->host->invalidate();
        });
    }

    /** The persisted diff-pane toggles: split or unified, whitespace shown or hidden. */
    private function applyPrefs(): void
    {
        $prefs = $this->prefs->read();
        DiffLayout::Split === $prefs['layout'] ? $this->panes->diffView->useSplitView() : $this->panes->diffView->useUnifiedView();
        $this->hideWhitespace = $prefs['hideWhitespace'];
        $this->hideWhitespace ? $this->panes->diffView->hideWhitespace() : $this->panes->diffView->showWhitespace();
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
        $this->poll = EventLoop::repeat($interval, function (): void {
            $this->refresh(RefreshMode::Quiet);
            $this->ageTimes();
            // An operation the palette started, with the view already up.
            $this->spinWhileRunning();
        });
    }

    /**
     * Turn the remote icon while an operation runs, GitHub Desktop's spinning
     * arrows: a step every {@see SPIN_SECONDS}, until the operation is over —
     * answered, failed or cancelled — or the view closes.
     */
    private function spinWhileRunning(): void
    {
        $operation = $this->remoteCommands?->running();
        if (null !== $this->spinner || null === $operation || !$this->open) {
            return;
        }
        $this->panes->toolbar->spin($operation);
        $this->host->invalidate();
        $this->spinner = EventLoop::repeat(self::SPIN_SECONDS, function (): void {
            $running = $this->remoteCommands?->running();
            if (null === $running) {
                $this->stopSpinning();

                return;
            }
            $this->panes->toolbar->spin($running);
            $this->host->invalidate();
        });
    }

    private function stopSpinning(): void
    {
        if (null !== $this->spinner) {
            EventLoop::cancel($this->spinner);
            $this->spinner = null;
        }
        $this->panes->toolbar->settle();
        $this->host->invalidate();
    }

    /**
     * "Last fetched 3 minutes ago" and "2 hours ago" are worked out when a
     * widget paints, and a widget repaints only once marked stale — so the
     * poll marks the two that say them, or they would read the same all day.
     */
    private function ageTimes(): void
    {
        // And every few ticks, ask git again: a checkout, a fetch or a push
        // run in the terminal moves the toolbar without telling the view.
        if (0 === (++$this->ticks % self::REREAD_TICKS)) {
            $this->repository?->refresh();
        }
        $this->panes->toolbar->invalidate();
        $this->panes->history->invalidate();
        $this->host->invalidate();
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
        $this->stopSpinning();
        $this->host->restore();
        $ended = $this->onComparisonClosed;
        if (null !== $ended) {
            $this->onComparisonClosed = null;
            $this->comparedDirs = null;
            $this->panes->diffView->makeEditable();
            $ended();
        }
    }

    /**
     * Make every status and diff still on its way stale, before a comparison
     * takes the pane.
     *
     * Their callbacks drop an answer for a CLOSED view, but a comparison opens
     * the view again — so a slow `git status` from when the view opened, or a
     * file's diff it went on to load, landed on an open view with a current
     * generation and painted the working tree over the comparison. Moving the
     * generations on is what says "not for this view any more". A plain
     * reopen needs none of it: its own status supersedes the old one.
     */
    private function forgetInFlight(): void
    {
        ++$this->statusGeneration;
        ++$this->diffGeneration;
    }

    /** Re-read `git status`; {@see RefreshMode::Quiet} skips the repaint when nothing changed. */
    public function refresh(RefreshMode $mode = RefreshMode::Eager): void
    {
        if (null !== $this->onComparisonClosed) {
            return; // a comparison is not the working tree; its pane is not the status's to overwrite
        }
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
            if (!$this->open || (RefreshMode::Quiet === $mode && $raw === $this->lastStatusRaw)) {
                return;
            }
            $this->lastStatusRaw = $raw;
            $this->entries = $entries;
            $this->panes->changes->setEntries($entries);
            if (null !== $this->selectOnLoad) {
                $this->panes->changes->selectPath($this->selectOnLoad);
                $this->selectOnLoad = null;
            }
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

        $context = self::CONTEXT_STEPS[$this->contextStep];
        $whitespace = $this->hideWhitespace ? WhitespaceMode::Hidden : WhitespaceMode::Shown;
        $this->shownSide = match (true) {
            $entry->isUntracked() => DiffSide::Untracked,
            $entry->isUnmerged(), ' ' !== $entry->worktreeStatus => DiffSide::Worktree,
            default => DiffSide::Cached,
        };
        match (true) {
            $entry->isUntracked() => $this->git->untrackedFileDiff($abs, $show),
            $entry->isUnmerged() => $this->git->conflictFileDiff($abs, $show, $context),
            DiffSide::Worktree === $this->shownSide => $this->git->worktreeFileDiff($abs, $show, $context, $whitespace),
            default => $this->git->cachedFileDiff($abs, $show, $context, $whitespace),
        };
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
        // The working tree's diff only: History's pane shows a commit, and
        // shownEntry is still the last file Changes opened.
        if (null === $this->shownEntry || GitViewMode::History === $this->mode) {
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
        $this->staging->commit(
            $message,
            $mode,
            function (): void {
                $this->dispatcher->dispatch(new GitCommitted());
                $this->repository?->refresh(); // one more commit ahead of the upstream
            },
            $this->refresh(...),
        );
    }

    /** Swap the left pane between the Changes and History tabs. */
    private function switchMode(GitViewMode $mode): void
    {
        if ($mode === $this->mode) {
            return;
        }
        $this->mode = $mode;
        $this->panes->tabs->setActive($mode);
        $this->arrange($mode);
        if (GitViewMode::History === $mode) {
            $this->showCommit(null);
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
        $this->panes->diffView->makeEditable();
        $this->host->focus($this->panes->changes);
        $this->refresh();
        $this->host->invalidate();
    }

    /**
     * Fill both halves for $mode: Changes is the list and the commit box
     * beside the diff; History is the commits beside the selected commit's
     * details and its diff, GitHub Desktop's three columns.
     */
    private function arrange(GitViewMode $mode): void
    {
        $this->region->sidebar()->clear();
        $this->region->content()->clear();
        $this->split->release();
        $this->region->sidebar()->add($this->panes->tabs);
        // The toolbar's cells stand over these columns, and the columns just changed.
        $this->panes->toolbar->invalidate();
        if (GitViewMode::History === $mode) {
            $this->region->sidebar()->add($this->panes->history);
            $this->split->mount($this->panes->details, $this->panes->diffView);
            $this->region->content()->add($this->panes->header)->add($this->split->area);

            return;
        }
        $this->region->sidebar()->add($this->panes->changes)->add($this->panes->commitBox);
        $this->region->content()->add($this->panes->diffView);
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
                $this->showCommit($selected);
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
        null !== $this->comparedDirs ? $this->compareEntry($event->entry) : $this->loadDiff($event->entry);
    }

    /**
     * A comparison lists files git handed over, not the repository's changes:
     * staging or discarding one of its rows would act on the real index and
     * working tree behind it. (Committing and line staging are off already —
     * see {@see openComparison()}.)
     */
    private function comparing(): bool
    {
        return null !== $this->onComparisonClosed;
    }

    public function onChangeStageToggled(GitChangeStageToggleRequested $event): void
    {
        if ($this->comparing()) {
            return;
        }
        $this->toggleStage($event->entry);
    }

    public function onStageAllRequested(GitStageAllRequested $event): void
    {
        if ($this->comparing()) {
            return;
        }
        $this->staging->stageAll($event->stage, $this->refresh(...));
    }

    public function onChangeDiscarded(GitChangeDiscardRequested $event): void
    {
        if ($this->comparing()) {
            return;
        }
        $this->discard($event->entry);
    }

    public function onCommitSelected(GitCommitSelected $event): void
    {
        // The header says which commit at once; its files follow from git.
        $this->showCommit($event->commit);
        $this->loadCommitFiles($event->commit);
    }

    /** Head both History columns with $commit, its stat and files still to come. */
    private function showCommit(?LogEntry $commit): void
    {
        $this->panes->header->setCommit($commit);
        $this->panes->details->setCommit($commit);
    }

    /** The commit header's copy button: the full sha, said back by its short one. */
    public function onCommitHashCopyRequested(GitCommitHashCopyRequested $event): void
    {
        ($this->copy)($event->commit->sha);
        $this->notifier->flash($this->translator->trans('Copied %sha%', ['%sha%' => $event->commit->shortSha]));
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

    /**
     * The diff's ⚙: whitespace hidden or shown, and unified or split — each
     * item runs what its key (w, s) runs, and is checked while it holds.
     */
    public function onDiffSettingsRequested(GitDiffSettingsRequested $event): void
    {
        $diff = $this->panes->diffView;
        $this->menus->openDiffSettings([
            new MenuItem($this->translator->trans('Hide whitespace changes'), 'W', $this->toggleWhitespace(...), isChecked: $diff->isWhitespaceHidden(...)),
            MenuItem::separator(),
            new MenuItem($this->translator->trans('Unified'), 'S', static fn () => $diff->chooseLayout(DiffLayout::Unified), isChecked: static fn (): bool => !$diff->isSplit()),
            new MenuItem($this->translator->trans('Split'), 'S', static fn () => $diff->chooseLayout(DiffLayout::Split), isChecked: $diff->isSplit(...)),
        ]);
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
        $this->spinWhileRunning();
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
        $this->stopSpinning();
        if ($this->open) {
            // A fetch moves what the toolbar says: behind, last fetched, and
            // maybe a pull request opened on the server since.
            $this->repository?->refresh(RefreshScope::Remote);
        }
        if ($this->open && GitViewMode::History === $this->mode) {
            $this->reloadHistory(HistoryReload::KeepSelection);
        }
    }

    /** A branch switch, a stash, a stage: the toolbar's branch and its distance may have moved. */
    public function onWorkingTreeChanged(WorkingTreeChanged $event): void
    {
        if ($this->open) {
            $this->repository?->refresh();
        }
    }

    /** A toolbar cell that drops a menu was pressed: the repository's worktrees or the branch verbs. */
    public function onToolbarMenuRequested(GitToolbarMenuRequested $event): void
    {
        $this->menus->openToolbarMenu($event->cell);
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
        // Each answer is for the commit still selected, or for none: arrowing
        // through the list asks per step, and a big commit answering last
        // would head another's files with its own count.
        $this->git->commitStat($commit->sha, function (?DiffStat $stat) use ($commit): void {
            if ($this->showing($commit)) {
                $this->panes->header->setStat($stat);
                $this->host->invalidate();
            }
        });
        $this->git->commitFiles($commit->sha, function (array $files) use ($commit): void {
            if (!$this->showing($commit)) {
                return;
            }
            $this->panes->details->setFiles($files);
            if ($this->split->isNarrow()) {
                $this->panes->header->nameFiles(\count($files));
            }
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

    /** Whether History is up with $commit the one selected. */
    private function showing(LogEntry $commit): bool
    {
        return $this->open && GitViewMode::History === $this->mode && $commit->sha === $this->panes->history->selectedCommit()?->sha;
    }

    private function loadCommitFileDiff(LogEntry $commit, string $path): void
    {
        $this->shownCommit = $commit;
        $this->shownCommitPath = $path;
        // The newest ask wins: arrowing through files starts a diff per step,
        // and a large file answering last would show beside the wrong one.
        $generation = ++$this->diffGeneration;
        $this->git->commitFileDiff(
            $commit->sha,
            $path,
            function (string $raw) use ($path, $generation): void {
                if (!$this->open || GitViewMode::History !== $this->mode || $generation !== $this->diffGeneration) {
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

    /** Tab order: changes list → diff → commit box → changes list (history: commits → files → diff). */
    public function focusNext(): void
    {
        $this->cycleFocus(1);
    }

    public function cycleFocus(int $direction): bool
    {
        /** @var list<FocusableInterface&AbstractWidget> $order */
        $order = GitViewMode::History === $this->mode
            ? [$this->panes->history, $this->panes->details, $this->panes->diffView]
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
