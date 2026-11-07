<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Command;

use Porta\DiffPlugin\DiffDocument;
use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\Event\GitFileHistoryRequested;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Parse\BranchParser;
use Porta\GitPlugin\Support\Flash;
use Porta\GitPlugin\Support\Modals;
use Porta\GitPlugin\Support\Translate;
use Porta\GitPlugin\View\GitViewController;
use Porta\GitPlugin\Worktree;
use Porta\Support\Outcome;
use Porta\Ui\Glyphs;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The git actions invoked from the editor/command palette — stage the current
 * file and commit. Each is a thin orchestration of the
 * async {@see GitInfo} client plus a status flash and a redraw; keeping them
 * here leaves the IDE coordinator out of the git-command business. The full
 * changes/diff experience lives in {@see GitViewController}.
 *
 */
final readonly class GitCommands
{
    public function __construct(
        private GitInfo $git,
        private Modals $editor,
        private Flash $notifier,
        /** @var \Closure(): void asks for a frame */
        private \Closure $repaint,
        private Translate $translator,
        private GitRefresher $refresher,
        /** @var \Closure(string): void copies to the system clipboard */
        private \Closure $copy,
        private EventDispatcherInterface $dispatcher,
        /** @var \Closure(): list<string> the files selected in the project tree, in tree order */
        private \Closure $selectedFiles,
        /** @var \Closure(list<string>, string): void publishes the compared lines under a title */
        private \Closure $showComparison,
        private WorktreeCommands $worktrees,
    ) {}

    public function commit(): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->editor->openPrompt($this->translator->trans('Commit message'), '', function (string $message): void {
            if ('' === \trim($message)) {
                return;
            }
            $this->git->commit($message, function (Outcome $ok, string $said) use ($message): void {
                $this->notifier->flash(
                    $ok->isOk()
                        ? $this->translator->trans('Committed: %summary%', ['%summary%' => $message])
                        : $this->translator->trans('Commit failed: %summary%', ['%summary%' => $said]),
                );
                $this->refresher->refreshBranch();
                $this->dispatcher->dispatch(new WorkingTreeChanged());
            });
        });
    }

    /** The stash picker: pick a stash, then apply, pop or drop it. */
    public function stashes(): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->git->stashList(function (array $stashes): void {
            if ([] === $stashes) {
                $this->notifier->flash($this->translator->trans('No stashes'));

                return;
            }
            // Picked by its COMMIT, not its position: `stash@{1}` names
            // whichever stash is second when it is read, and a stash pushed or
            // popped in a terminal while these pickers were open moved it —
            // Drop then took another one.
            $items = \array_map(static fn (array $stash): array => [
                'label' => '  '.$stash['subject'],
                'hint' => \date('Y-m-d H:i', $stash['timestamp']),
                'value' => $stash['commit'],
            ], $stashes);
            $refs = \array_column($stashes, column_key: 'ref', index_key: 'commit');
            $this->editor->openListOverlay($this->translator->trans('Stashes'), $items, function (string $commit) use ($refs): void {
                $ref = $refs[$commit] ?? $commit;
                $this->editor->openListOverlay(
                    $this->translator->trans('%stash%: apply, pop or drop?', ['%stash%' => $ref]),
                    [
                        ['label' => $this->translator->trans('Apply'), 'hint' => '', 'value' => 'apply'],
                        ['label' => $this->translator->trans('Pop'), 'hint' => '', 'value' => 'pop'],
                        ['label' => $this->translator->trans('Drop'), 'hint' => '', 'value' => 'drop'],
                        ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'cancel'],
                    ],
                    function (string $action) use ($commit): void {
                        if ('cancel' !== $action) {
                            $this->onStash($commit, $action);
                        }
                    },
                );
            });
        });
    }

    /**
     * $action on the stash whose commit is $commit, at the position it has
     * NOW — read again, as the list may have moved since it was shown. Gone
     * (popped or dropped elsewhere), nothing is done to another in its place.
     */
    private function onStash(string $commit, string $action): void
    {
        $this->git->stashList(function (array $stashes) use ($commit, $action): void {
            $ref = \array_column($stashes, column_key: 'ref', index_key: 'commit')[$commit] ?? null;
            if (null === $ref) {
                $this->notifier->error($this->translator->trans('Stash failed'), 6.0);

                return;
            }
            $this->act($ref, $action);
        });
    }

    private function act(string $ref, string $action): void
    {
        match ($action) {
            'apply' => $this->git->stashApply($ref, fn (Outcome $ok, string $error) => $this->finishSwitch(
                $ok,
                $this->stashError($error),
                $this->translator->trans('Applied %stash%', ['%stash%' => $ref]),
            )),
            'pop' => $this->git->stashPop($ref, fn (Outcome $ok, string $error) => $this->finishSwitch(
                $ok,
                $this->stashError($error),
                $this->translator->trans('Popped %stash%', ['%stash%' => $ref]),
            )),
            // Drop, the third of the three (Cancel never gets here). It never
            // touches the worktree — no marks refresh.
            default => $this->git->stashDrop($ref, function (Outcome $ok, string $error) use ($ref): void {
                $ok->isOk() ? $this->notifier->success($this->translator->trans('Dropped %stash%', ['%stash%' => $ref]), 3.0) : $this->notifier->error($error, 6.0);
            }),
        };
    }

    /** Open the git view's History tab filtered to the current file. */
    public function fileHistory(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path || !$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        // The registry used to carry an openGitFileHistory() verb over a
        // concrete GitViewController field, which is what made Workbench
        // depend on Git. The view performs its own capability now.
        $this->dispatcher->dispatch(new GitFileHistoryRequested($path));
    }

    /**
     * Compare the two files selected in the project tree.
     *
     * Two, exactly: one is what the tree always has (its cursor row), and
     * three has no meaning here — so the count is the command's whole
     * precondition, and saying which is wrong beats doing nothing.
     *
     * The result goes to a list panel rather than the diff view. That view is
     * bound to the repository's state machine — its poll reloads the pane, its
     * keys stage lines into the index — and a pair of arbitrary files is
     * neither a change nor a commit.
     *
     * The rows start at the first hunk. git's header names the two files, and
     * so does the panel's own title — but a short panel shows its first rows,
     * and five lines of header is exactly enough to push the first real change
     * out of sight.
     */
    public function compareFiles(): void
    {
        $paths = ($this->selectedFiles)();
        if (2 !== \count($paths)) {
            $this->notifier->flash($this->translator->trans('Select two files in the project tree to compare'));

            return;
        }
        // `--no-index` itself needs no repository — but this plugin's runner
        // does: it answers every call with a failed run when there is no
        // working tree, deliberately, as the one place that knows the
        // availability rule ({@see \Porta\GitPlugin\Process\GitProcessRunner}).
        // So a comparison is available exactly where the rest of this plugin
        // is, and says the same thing when it is not.
        if (!$this->guardAvailable()) {
            return;
        }

        [$left, $right] = $paths;
        $title = \basename($left).' ↔ '.\basename($right);
        $this->git->compareFiles($left, $right, function (string $raw) use ($title): void {
            $lines = self::hunks($raw);
            if ([] === $lines) {
                // No hunk is not "the same": two binary files that differ print
                // no hunk either — as the git view's comparison already says.
                $this->notifier->flash($this->translator->trans(DiffDocument::parse($raw)->isBinary() ? 'Binary files differ' : 'The two files are identical'));

                return;
            }
            ($this->showComparison)($lines, $title);
        });
    }

    /**
     * A raw diff from its first hunk header on, empty when the files match.
     *
     * @return list<string>
     */
    private static function hunks(string $raw): array
    {
        $lines = \explode("\n", \rtrim($raw, characters: "\n"));
        $hunks = \array_keys(\array_filter($lines, static fn (string $line): bool => \str_starts_with($line, '@@')));

        return [] === $hunks ? [] : \array_slice($lines, $hunks[0]);
    }

    /** Copy a commit's full hash to the clipboard (the history context menu). */
    public function copyCommitHash(LogEntry $commit): void
    {
        ($this->copy)($commit->sha);
        $this->notifier->flash($this->translator->trans('Copied %sha%', ['%sha%' => $commit->shortSha]));
    }

    /** Prompt for a name, then branch off $commit and switch to it. */
    public function branchFromCommit(LogEntry $commit): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->editor->openPrompt($this->translator->trans('New branch name'), '', function (string $name) use ($commit): void {
            $name = \trim($name);
            if ('' === $name) {
                return;
            }
            $this->git->branchFromCommit($name, $commit->sha, fn (Outcome $ok, string $error) => $this->finishHistoryOp($ok, $error, $this->translator->trans('Created and switched to %branch%', [
                '%branch%' => $name,
            ])));
        });
        ($this->repaint)();
    }

    /** Cherry-pick $commit onto the current branch (a conflict flashes git's message). */
    public function cherryPick(LogEntry $commit): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->git->cherryPick($commit->sha, fn (Outcome $ok, string $error) => $this->finishHistoryOp($ok, $error, $this->translator->trans('Cherry-picked %sha%', ['%sha%' => $commit->shortSha])));
    }

    /** Revert $commit as a new commit (a conflict flashes git's message). */
    public function revertCommit(LogEntry $commit): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->git->revertCommit($commit->sha, fn (Outcome $ok, string $error) => $this->finishHistoryOp($ok, $error, $this->translator->trans('Reverted %sha%', ['%sha%' => $commit->shortSha])));
    }

    /** Reset HEAD to $commit, confirming the soft/mixed mode first (never --hard). */
    public function resetToCommit(LogEntry $commit): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->editor->openListOverlay(
            $this->translator->trans('Reset to %sha%?', ['%sha%' => $commit->shortSha]),
            [
                ['label' => $this->translator->trans('Soft (keep staged changes)'), 'hint' => '', 'value' => 'soft'],
                ['label' => $this->translator->trans('Mixed (unstage changes)'), 'hint' => '', 'value' => 'mixed'],
                ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'cancel'],
            ],
            function (string $mode) use ($commit): void {
                if ('soft' !== $mode && 'mixed' !== $mode) {
                    return;
                }
                $this->git->resetTo($commit->sha, $mode, fn (Outcome $ok, string $error) => $this->finishHistoryOp($ok, $error, $this->translator->trans('Reset to %sha% (%mode%)', [
                    '%sha%' => $commit->shortSha,
                    '%mode%' => $mode,
                ])));
            },
        );
        ($this->repaint)();
    }

    private function guardAvailable(): bool
    {
        if ($this->git->isAvailable()) {
            return true;
        }
        $this->unavailable();

        return false;
    }

    /**
     * Finish a history operation: flash the outcome and refresh the branch label
     * and gutter marks. Unlike a plain branch switch this refreshes on failure
     * too — a cherry-pick/revert conflict changes the working tree even as it
     * "fails".
     */
    private function finishHistoryOp(Outcome $ok, string $error, string $success): void
    {
        $ok->isOk() ? $this->notifier->success($success, 3.0) : $this->notifier->error($error, 6.0);
        $this->refresher->refreshBranch();
        $this->dispatcher->dispatch(new WorkingTreeChanged());
    }

    /** A conflicting pop/apply reports on stdout, not stderr — provide the message git doesn't. */
    private function stashError(string $error): string
    {
        return '' !== $error ? $error : $this->translator->trans('Stash left conflicts — resolve them in the editor');
    }

    private function unavailable(): void
    {
        $this->notifier->flash($this->translator->trans('Git not available'));
    }

    /** Sentinel row values — a "\n" can never appear in a refname. */
    private const string CREATE = "create\nbranch";
    private const string DELETE = "delete\nbranch";
    private const string REMOTE_PREFIX = "remote\n";
    private const string NEW_WORKTREE = "new\nworktree";
    private const string WORKTREE_PREFIX = "worktree\n";

    /**
     * The branch picker: local branches (newest activity first), unshadowed
     * remote branches (checked out as tracking branches), a create row and a
     * delete row. Checkout failures flash git's own message — no auto-stash.
     *
     * A branch another worktree has checked out cannot be checked out here —
     * git refuses — so picking it opens that worktree instead.
     */
    public function branches(): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->withBranches(function (array $branches, array $holders): void {
            $items = [
                ['label' => $this->translator->trans('+ Create new branch…'), 'hint' => '', 'value' => self::CREATE],
                ['label' => $this->translator->trans('− Delete branch…'), 'hint' => '', 'value' => self::DELETE],
                ['label' => $this->translator->trans('+ New worktree…'), 'hint' => '', 'value' => self::NEW_WORKTREE],
            ];
            foreach ($branches as $branch) {
                $holder = $branch['remote'] ? null : $holders[$branch['name']] ?? null;
                $hint = match (true) {
                    $branch['remote'] => $this->translator->trans('remote'),
                    $branch['current'] => $this->translator->trans('current'),
                    null !== $holder => $this->translator->trans('in worktree %name%', ['%name%' => $holder->name()]),
                    default => '',
                };
                $items[] = [
                    'label' => ($branch['current'] ? Glyphs::BRANCH_CURRENT.' ' : '  ').$branch['name'],
                    'hint' => $hint,
                    'value' => match (true) {
                        $branch['remote'] => self::REMOTE_PREFIX.$branch['name'],
                        null !== $holder => self::WORKTREE_PREFIX.$holder->path,
                        default => $branch['name'],
                    },
                ];
            }
            $this->editor->openListOverlay($this->translator->trans('Branches'), $items, function (string $value): void {
                match (true) {
                    self::CREATE === $value => $this->promptNewBranch(),
                    self::DELETE === $value => $this->pickBranchToDelete(),
                    self::NEW_WORKTREE === $value => $this->worktrees->newWorktree(),
                    \str_starts_with($value, self::REMOTE_PREFIX) => $this->checkoutTracking(\substr($value, \strlen(self::REMOTE_PREFIX))),
                    \str_starts_with($value, self::WORKTREE_PREFIX) => $this->worktrees->open(\substr($value, \strlen(self::WORKTREE_PREFIX))),
                    default => $this->checkout($value),
                };
            });
            ($this->repaint)();
        });
    }

    /**
     * The branches, and those other worktrees have checked out, by name —
     * asked of git at once, not one after the other. No holders when git
     * cannot list worktrees: an old git still switches branches here.
     *
     * A worktree whose folder is gone holds its branch too, but there is
     * nothing to open: git's own refusal to check it out says where it is.
     *
     * @param \Closure(list<array{name: string, current: bool, remote: bool}>, array<string, Worktree>): void $then
     */
    private function withBranches(\Closure $then): void
    {
        /** @var list<array{name: string, current: bool, remote: bool}>|null $branches filled by its own git call */
        $branches = null;
        /** @var array<string, Worktree>|null $holders filled by its own git call */
        $holders = null;
        $join = static function () use (&$branches, &$holders, $then): void {
            if (null !== $branches && null !== $holders) {
                $then($branches, $holders);
            }
        };
        $this->git->worktrees(
            /** @param list<Worktree>|null $worktrees */
            function (?array $worktrees) use (&$holders, $join): void {
                $holders = [];
                foreach ($worktrees ?? [] as $worktree) {
                    if (null === $worktree->branch || $worktree->prunable || $this->worktrees->isCurrent($worktree)) {
                        continue;
                    }
                    $holders[$worktree->branch] = $worktree;
                }
                $join();
            },
        );
        $this->git->branches(static function (array $found) use (&$branches, $join): void {
            $branches = $found;
            $join();
        });
    }

    /** Direct new-branch prompt (the status-bar branch menu's "New Branch…"). */
    public function newBranch(): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->promptNewBranch();
    }

    /** Direct delete-branch picker (the status-bar branch menu's "Delete Branch…"). */
    public function deleteBranch(): void
    {
        if (!$this->guardAvailable()) {
            return;
        }
        $this->pickBranchToDelete();
    }

    /**
     * Second picker: local branches minus the current one and those other
     * worktrees have checked out — git refuses to delete those — then a confirm.
     */
    private function pickBranchToDelete(): void
    {
        $this->withBranches(function (array $branches, array $holders): void {
            $deletable = \array_values(\array_filter($branches, static fn (array $b): bool => !$b['remote'] && !$b['current'] && !\array_key_exists($b['name'], $holders)));
            if ([] === $deletable) {
                $this->notifier->flash($this->translator->trans('No other local branches to delete'));

                return;
            }
            $items = \array_map(static fn (array $b): array => ['label' => '  '.$b['name'], 'hint' => '', 'value' => $b['name']], $deletable);
            $this->editor->openListOverlay($this->translator->trans('Delete branch'), $items, function (string $name): void {
                $this->editor->openListOverlay(
                    $this->translator->trans('Delete %branch%?', ['%branch%' => $name]),
                    [
                        ['label' => $this->translator->trans('Delete'), 'hint' => '', 'value' => 'yes'],
                        ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'no'],
                    ],
                    function (string $confirm) use ($name): void {
                        if ('yes' !== $confirm) {
                            return;
                        }
                        // -d only: an unmerged branch fails with git's message,
                        // which itself names the -D escape hatch.
                        $this->git->deleteBranch($name, function (Outcome $ok, string $error) use ($name): void {
                            $ok->isOk() ? $this->notifier->success($this->translator->trans('Deleted branch %branch%', ['%branch%' => $name]), 3.0) : $this->notifier->error($error, 6.0);
                        });
                    },
                );
            });
        });
    }

    /** Check out origin/x as a local tracking branch x. */
    private function checkoutTracking(string $remoteBranch): void
    {
        $local = BranchParser::shortName($remoteBranch);
        $this->git->checkoutTracking($remoteBranch, fn (Outcome $ok, string $error) => $this->finishSwitch($ok, $error, $this->translator->trans('Switched to %branch% (tracking %remote%)', [
            '%branch%' => $local,
            '%remote%' => $remoteBranch,
        ])));
    }

    private function promptNewBranch(): void
    {
        $this->editor->openPrompt($this->translator->trans('New branch name'), '', function (string $name): void {
            $name = \trim($name);
            if ('' === $name) {
                return;
            }
            $this->git->createBranch($name, fn (Outcome $ok, string $error) => $this->finishSwitch($ok, $error, $this->translator->trans('Created and switched to %branch%', ['%branch%' => $name])));
        });
        ($this->repaint)();
    }

    private function checkout(string $branch): void
    {
        $this->git->checkout($branch, fn (Outcome $ok, string $error) => $this->finishSwitch($ok, $error, $this->translator->trans('Switched to %branch%', ['%branch%' => $branch])));
    }

    private function finishSwitch(Outcome $ok, string $error, string $success): void
    {
        $ok->isOk() ? $this->notifier->success($success, 3.0) : $this->notifier->error($error, 6.0);
        if ($ok->isOk()) {
            // HEAD moved and files may have changed on disk; the filesystem
            // poll reconciles open buffers, these refresh the chrome now.
            $this->refresher->refreshBranch();
            $this->dispatcher->dispatch(new WorkingTreeChanged());
        }
        ($this->repaint)();
    }
}
