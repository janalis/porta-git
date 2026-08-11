<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Command;

use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\Event\GitFileHistoryRequested;
use Porta\GitPlugin\GitInfo;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\LogEntry;
use Porta\GitPlugin\Support\Flash;
use Porta\GitPlugin\Support\Modals;
use Porta\GitPlugin\Support\Translate;
use Porta\GitPlugin\View\GitViewController;
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
final class GitCommands
{
    public function __construct(
        private readonly GitInfo $git,
        private readonly Modals $editor,
        private readonly Flash $notifier,
        /** @var \Closure(): void asks for a frame */
        private readonly \Closure $repaint,
        private readonly Translate $translator,
        private readonly GitRefresher $refresher,
        /** @var \Closure(string): void copies to the system clipboard */
        private readonly \Closure $copy,
        private readonly EventDispatcherInterface $dispatcher,
    ) {}

    public function commit(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->editor->openPrompt($this->translator->trans('Commit message'), '', function (string $message): void {
            if ('' === trim($message)) {
                return;
            }
            $this->git->commit($message, function (Outcome $ok) use ($message): void {
                $this->notifier->flash(
                    $ok->isOk()
                        ? $this->translator->trans('Committed: %summary%', ['%summary%' => $message])
                        : $this->translator->trans('Commit failed (nothing staged?)'),
                );
                $this->refresher->refreshBranch();
                $this->dispatcher->dispatch(new WorkingTreeChanged());
            });
        });
    }

    /** The stash picker: pick a stash, then apply, pop or drop it. */
    public function stashes(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->git->stashList(function (array $stashes): void {
            if ([] === $stashes) {
                $this->notifier->flash($this->translator->trans('No stashes'));

                return;
            }
            $items = array_map(static fn (array $stash): array => [
                'label' => '  '.$stash['subject'],
                'hint' => date('Y-m-d H:i', $stash['timestamp']),
                'value' => $stash['ref'],
            ], $stashes);
            $this->editor->openListOverlay($this->translator->trans('Stashes'), $items, function (string $ref): void {
                $this->editor->openListOverlay(
                    $this->translator->trans('%stash%: apply, pop or drop?', ['%stash%' => $ref]),
                    [
                        ['label' => $this->translator->trans('Apply'), 'hint' => '', 'value' => 'apply'],
                        ['label' => $this->translator->trans('Pop'), 'hint' => '', 'value' => 'pop'],
                        ['label' => $this->translator->trans('Drop'), 'hint' => '', 'value' => 'drop'],
                        ['label' => $this->translator->trans('Cancel'), 'hint' => '', 'value' => 'cancel'],
                    ],
                    function (string $action) use ($ref): void {
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
                            // Drop never touches the worktree — no marks refresh.
                            'drop' => $this->git->stashDrop($ref, function (Outcome $ok, string $error) use ($ref): void {
                                $this->notifier->flash($ok->isOk() ? $this->translator->trans('Dropped %stash%', ['%stash%' => $ref]) : $error, $ok->isOk() ? 3.0 : 6.0);
                            }),
                            default => null,
                        };
                    },
                );
            });
        });
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
            $name = trim($name);
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
        $this->notifier->flash($ok->isOk() ? $success : $error, $ok->isOk() ? 3.0 : 6.0);
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

    /**
     * The branch picker: local branches (newest activity first), unshadowed
     * remote branches (checked out as tracking branches), a create row and a
     * delete row. Checkout failures flash git's own message — no auto-stash.
     */
    public function branches(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->git->branches(function (array $branches): void {
            $items = [
                ['label' => $this->translator->trans('+ Create new branch…'), 'hint' => '', 'value' => self::CREATE],
                ['label' => $this->translator->trans('− Delete branch…'), 'hint' => '', 'value' => self::DELETE],
            ];
            foreach ($branches as $branch) {
                $hint = match (true) {
                    $branch['remote'] => $this->translator->trans('remote'),
                    $branch['current'] => $this->translator->trans('current'),
                    default => '',
                };
                $items[] = [
                    'label' => ($branch['current'] ? Glyphs::BRANCH_CURRENT.' ' : '  ').$branch['name'],
                    'hint' => $hint,
                    'value' => $branch['remote'] ? self::REMOTE_PREFIX.$branch['name'] : $branch['name'],
                ];
            }
            $this->editor->openListOverlay($this->translator->trans('Branches'), $items, function (string $value): void {
                match (true) {
                    self::CREATE === $value => $this->promptNewBranch(),
                    self::DELETE === $value => $this->pickBranchToDelete(),
                    str_starts_with($value, self::REMOTE_PREFIX) => $this->checkoutTracking(substr($value, \strlen(self::REMOTE_PREFIX))),
                    default => $this->checkout($value),
                };
            });
            ($this->repaint)();
        });
    }

    /** Direct new-branch prompt (the status-bar branch menu's "New Branch…"). */
    public function newBranch(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->promptNewBranch();
    }

    /** Direct delete-branch picker (the status-bar branch menu's "Delete Branch…"). */
    public function deleteBranch(): void
    {
        if (!$this->git->isAvailable()) {
            $this->unavailable();

            return;
        }
        $this->pickBranchToDelete();
    }

    /** Second picker: local branches minus the current one, then a confirm. */
    private function pickBranchToDelete(): void
    {
        $this->git->branches(function (array $branches): void {
            $deletable = array_values(array_filter($branches, static fn (array $b): bool => !$b['remote'] && !$b['current']));
            if ([] === $deletable) {
                $this->notifier->flash($this->translator->trans('No other local branches to delete'));

                return;
            }
            $items = array_map(static fn (array $b): array => ['label' => '  '.$b['name'], 'hint' => '', 'value' => $b['name']], $deletable);
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
                            $this->notifier->flash($ok->isOk() ? $this->translator->trans('Deleted branch %branch%', ['%branch%' => $name]) : $error, $ok->isOk() ? 3.0 : 6.0);
                        });
                    },
                );
            });
        });
    }

    /** Check out origin/x as a local tracking branch x. */
    private function checkoutTracking(string $remoteBranch): void
    {
        $slash = strpos($remoteBranch, needle: '/');
        $local = false === $slash ? $remoteBranch : substr($remoteBranch, $slash + 1);
        $this->git->checkoutTracking($remoteBranch, fn (Outcome $ok, string $error) => $this->finishSwitch($ok, $error, $this->translator->trans('Switched to %branch% (tracking %remote%)', [
            '%branch%' => $local,
            '%remote%' => $remoteBranch,
        ])));
    }

    private function promptNewBranch(): void
    {
        $this->editor->openPrompt($this->translator->trans('New branch name'), '', function (string $name): void {
            $name = trim($name);
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
        $this->notifier->flash($ok->isOk() ? $success : $error, $ok->isOk() ? 3.0 : 6.0);
        if ($ok->isOk()) {
            // HEAD moved and files may have changed on disk; the filesystem
            // poll reconciles open buffers, these refresh the chrome now.
            $this->refresher->refreshBranch();
            $this->dispatcher->dispatch(new WorkingTreeChanged());
        }
        ($this->repaint)();
    }
}
