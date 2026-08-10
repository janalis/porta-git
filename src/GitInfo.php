<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\Support\Outcome;

/**
 * Asynchronous git integration: current branch name and per-line change
 * marks (added / modified / deleted) computed from `git diff` against HEAD.
 *
 * Everything is best-effort and silently disabled when the project is not a
 * git working tree or the `git` binary is unavailable.
 */
final class GitInfo
{
    private readonly GitProcessRunner $runner;

    public function __construct(
        string $rootDir,
        private readonly ?GitSettings $gitSettings = null,
        ?string $gitPath = null,
    ) {
        $this->runner = new GitProcessRunner($rootDir, $gitPath);
    }

    /** The resolved `git` binary ({@see GitProcessRunner::binary()}), or null when none was found. */
    public function binary(): ?string
    {
        return $this->runner->binary();
    }

    public function isAvailable(): bool
    {
        return $this->runner->isAvailable();
    }

    public function isRepository(): bool
    {
        return $this->runner->isRepository();
    }

    /**
     * @param callable(?string): void $onDone branch name, or null when detached/unavailable
     */
    public function branch(callable $onDone): void
    {
        $this->runner->run(['rev-parse', '--abbrev-ref', 'HEAD'], static function (string $out) use ($onDone): void {
            $branch = trim($out);
            $onDone('' === $branch || 'HEAD' === $branch ? null : $branch);
        });
    }

    /**
     * Compute change marks for a file relative to HEAD.
     *
     * @param callable(array<int, string>): void $onDone 0-based line => 'added'|'modified'|'deleted'
     */
    public function diff(string $absPath, callable $onDone): void
    {
        $this->runner->run(['diff', '--no-color', '--unified=0', 'HEAD', '--', $absPath], static function (string $out) use ($onDone): void {
            $onDone(HunkParser::parse($out));
        });
    }

    /**
     * Every changed/untracked file in the working tree, for the changes list.
     *
     * @param callable(list<ChangeEntry>, string): void $onDone entries plus the raw
     *                                                  porcelain output (for cheap
     *                                                  change-detection between polls)
     */
    public function status(callable $onDone): void
    {
        $this->runner->run(['status', '--porcelain=v1', '-z', '--untracked-files=all'], static fn (string $out) => $onDone(StatusParser::parse($out), $out));
    }

    /**
     * The staged (index vs HEAD) diff of a file, for viewing/unstaging.
     *
     * @param callable(string): void $onDone     receives the raw diff
     * @param int|null               $context    surrounding lines per hunk (null = git's default)
     * @param WhitespaceMode         $whitespace {@see WhitespaceMode::Hidden} drops whitespace-only changes (-w)
     */
    public function cachedFileDiff(string $absPath, callable $onDone, ?int $context = null, WhitespaceMode $whitespace = WhitespaceMode::Shown): void
    {
        $this->runner->run(['diff', '--no-color', '--cached', ...(null === $context ? [] : ['-U'.$context]), ...(WhitespaceMode::Hidden === $whitespace ? ['-w'] : []), '--', $absPath], $onDone);
    }

    /**
     * The worktree-vs-index diff of a file (what line-staging operates on).
     *
     * @param callable(string): void $onDone     receives the raw diff
     * @param int|null               $context    surrounding lines per hunk (null = git's default)
     * @param WhitespaceMode         $whitespace {@see WhitespaceMode::Hidden} drops whitespace-only changes (-w)
     */
    public function worktreeFileDiff(string $absPath, callable $onDone, ?int $context = null, WhitespaceMode $whitespace = WhitespaceMode::Shown): void
    {
        $this->runner->run(['diff', '--no-color', ...(null === $context ? [] : ['-U'.$context]), ...(WhitespaceMode::Hidden === $whitespace ? ['-w'] : []), '--', $absPath], $onDone);
    }

    /**
     * A whole-file "diff" for an untracked file (everything is an addition).
     * `--no-index` exits 1 when the files differ, which is the success case.
     *
     * @param callable(string): void $onDone receives the raw diff
     */
    public function untrackedFileDiff(string $absPath, callable $onDone): void
    {
        $this->runner->runStatus(['diff', '--no-color', '--no-index', '--', '/dev/null', $absPath], static fn (int $code, string $out) => $onDone($out));
    }

    /**
     * Take a file's changes out of the index (`git restore --staged`).
     *
     * @param callable(Outcome): void $onDone
     */
    public function unstageFile(string $absPath, callable $onDone): void
    {
        $this->runner->runStatus(['restore', '--staged', '--', $absPath], static fn (int $code) => $onDone(Outcome::of(0 === $code)));
    }

    /**
     * Throw away a tracked file's worktree changes (`git restore`).
     * Untracked files are simply unlinked by the caller — no git verb needed.
     *
     * @param callable(Outcome): void $onDone
     */
    public function discardFile(string $absPath, callable $onDone): void
    {
        $this->runner->runStatus(['restore', '--', $absPath], static fn (int $code) => $onDone(Outcome::of(0 === $code)));
    }

    /**
     * Apply a crafted partial patch to the index (`git apply --cached -`),
     * the engine behind line/hunk staging and unstaging.
     *
     * @param callable(Outcome): void $onDone
     */
    public function applyCached(string $patch, callable $onDone): void
    {
        $this->runner->runStatus(['apply', '--cached', '--whitespace=nowarn', '-'], static fn (int $code) => $onDone(Outcome::of(0 === $code)), stdin: $patch);
    }

    /**
     * Stage a file (`git add`). Reports whether git exited cleanly.
     *
     * @param callable(Outcome): void $onDone
     */
    public function stage(string $absPath, callable $onDone): void
    {
        $this->runner->runStatus(['add', '--', $absPath], static fn (int $code) => $onDone(Outcome::of(0 === $code)));
    }

    /**
     * Commit the index (`git commit -m`), optionally amending the last
     * commit. Reports success and git's output.
     *
     * @param callable(Outcome, string): void $onDone
     */
    public function commit(string $message, callable $onDone, CommitMode $mode = CommitMode::Fresh): void
    {
        $head = CommitMode::Amend === $mode ? ['commit', '--amend'] : ['commit'];
        $args = [...$head, ...$this->commitFlags(), '-m', $message];
        $this->runner->runStatus($args, static fn (int $code, string $out) => $onDone(Outcome::of(0 === $code), trim($out)));
    }

    /**
     * The last commit's full message, for prefilling an amend.
     *
     * @param callable(string): void $onDone
     */
    public function lastCommitMessage(callable $onDone): void
    {
        $this->runner->run(['log', '-1', '--format=%B'], static fn (string $out) => $onDone(trim($out)));
    }

    /**
     * Undo the last commit, keeping its changes staged (`reset --soft`).
     * Refused when HEAD has no parent (nothing to undo onto).
     *
     * @param callable(Outcome): void $onDone
     */
    public function undoLastCommit(callable $onDone): void
    {
        $this->runner->runStatus(['rev-parse', '--verify', 'HEAD~1'], function (int $code) use ($onDone): void {
            if (0 !== $code) {
                $onDone(Outcome::Failed); // the root commit cannot be undone onto a parent

                return;
            }
            $this->runner->runStatus(['reset', '--soft', 'HEAD~1'], static fn (int $c) => $onDone(Outcome::of(0 === $c)));
        });
    }

    /**
     * The most recent commits, for the history tab (capped; paged via $skip).
     * With $path, only the commits that touched that file (no --follow — it
     * composes badly with --skip paging; a rename truncates the list).
     *
     * @param callable(list<LogEntry>): void $onDone
     * @param string|null                    $path   repo-relative file filter
     */
    public function log(int $limit, callable $onDone, int $skip = 0, ?string $path = null): void
    {
        $args = ['log', '-z', '-n', (string) $limit, '--format=%H%x1f%h%x1f%an%x1f%at%x1f%s'];
        if ($skip > 0) {
            $args[] = '--skip';
            $args[] = (string) $skip;
        }
        if (null !== $path) {
            $args[] = '--';
            $args[] = $path;
        }
        $this->runner->run($args, static fn (string $out) => $onDone(LogParser::parseLog($out)));
    }

    /**
     * Stash the working tree. Success flashes git's own first stdout line —
     * it distinguishes "Saved working directory…" from "No local changes to
     * save" without any content branching here.
     *
     * @param callable(Outcome, string): void $onDone (ok, first stdout line)
     */
    public function stashPush(callable $onDone): void
    {
        $this->runner->runStatus(['stash', 'push'], static function (int $code, string $out) use ($onDone): void {
            // @mago-expect lint:no-literal-password -- strtok's token: is a newline delimiter, not a credential
            $firstLine = strtok(trim($out), token: "\n");
            $onDone(Outcome::of(0 === $code), \is_string($firstLine) ? $firstLine : '');
        });
    }

    /**
     * Pop a stash (the newest without $ref). A conflicting pop fails with
     * git's own stderr line; the conflict markers land in the working tree.
     *
     * @param callable(Outcome, string): void $onDone
     */
    public function stashPop(?string $ref, callable $onDone): void
    {
        $this->runner->runWithErrors(['stash', 'pop', ...(null === $ref ? [] : [$ref])], $onDone);
    }

    /** Apply a stash without dropping it. @param callable(Outcome, string): void $onDone */
    public function stashApply(string $ref, callable $onDone): void
    {
        $this->runner->runWithErrors(['stash', 'apply', $ref], $onDone);
    }

    /** Drop a stash without touching the worktree. @param callable(Outcome, string): void $onDone */
    public function stashDrop(string $ref, callable $onDone): void
    {
        $this->runner->runWithErrors(['stash', 'drop', $ref], $onDone);
    }

    /**
     * The stash list, newest first.
     *
     * @param callable(list<array{ref: string, timestamp: int, subject: string}>): void $onDone
     */
    public function stashList(callable $onDone): void
    {
        $this->runner->run(['stash', 'list', '-z', '--format=%gd%x1f%at%x1f%gs'], static fn (string $out) => $onDone(LogParser::parseStashList($out)));
    }

    /**
     * Local branches (newest activity first), then unshadowed remote branches.
     *
     * @param callable(list<array{name: string, current: bool, remote: bool}>): void $onDone
     */
    public function branches(callable $onDone): void
    {
        $this->runner->run(['branch', '--all', '--sort=-committerdate', '--format=%(refname)%09%(HEAD)'], static fn (string $out) => $onDone(BranchParser::parse($out)));
    }

    /**
     * Delete a local branch with `-d` only: an unmerged branch fails with
     * git's own message (which names the -D escape hatch) — no force here.
     *
     * @param callable(Outcome, string): void $onDone
     */
    public function deleteBranch(string $name, callable $onDone): void
    {
        $this->runner->runWithErrors(['branch', '-d', $name], $onDone);
    }

    /**
     * Check out a remote branch as a local tracking branch — explicit
     * `-b --track`, not checkout's DWIM (which depends on checkout.guess and
     * misfires with multiple remotes).
     *
     * @param string $remoteBranch e.g. "origin/feature"
     * @param callable(Outcome, string): void $onDone
     */
    public function checkoutTracking(string $remoteBranch, callable $onDone): void
    {
        $slash = strpos($remoteBranch, needle: '/');
        $local = false === $slash ? $remoteBranch : substr($remoteBranch, $slash + 1);
        $this->runner->runWithErrors(['checkout', '-b', $local, '--track', $remoteBranch], $onDone);
    }

    /**
     * Switch branches. Failure surfaces git's own stderr (the dirty-working-
     * tree refusal in particular) — no auto-stash.
     *
     * @param callable(Outcome, string): void $onDone (ok, first stderr line on failure)
     */
    public function checkout(string $branch, callable $onDone): void
    {
        $this->runner->runWithErrors(['checkout', $branch], $onDone);
    }

    /** @param callable(Outcome, string): void $onDone (ok, first stderr line on failure — "already exists", invalid name) */
    public function createBranch(string $name, callable $onDone): void
    {
        $this->runner->runWithErrors(['checkout', '-b', $name], $onDone);
    }

    /**
     * Create branch $name pointing at $sha and switch to it.
     *
     * @param callable(Outcome, string): void $onDone (ok, first stderr line on failure)
     */
    public function branchFromCommit(string $name, string $sha, callable $onDone): void
    {
        $this->runner->runWithErrors(['checkout', '-b', $name, $sha], $onDone);
    }

    /**
     * Apply $sha as a fresh commit on the current branch. A conflict fails with
     * git's own message; the half-applied state lands in the working tree for
     * the user to resolve.
     *
     * @param callable(Outcome, string): void $onDone (ok, first stderr line on failure)
     */
    public function cherryPick(string $sha, callable $onDone): void
    {
        $this->runner->runWithErrors(['cherry-pick', $sha], $onDone);
    }

    /**
     * Create a commit that undoes $sha (git's default message, no editor). A
     * conflict fails with git's message and lands in the working tree.
     *
     * @param callable(Outcome, string): void $onDone (ok, first stderr line on failure)
     */
    public function revertCommit(string $sha, callable $onDone): void
    {
        $this->runner->runWithErrors(['revert', '--no-edit', $sha], $onDone);
    }

    /**
     * Move HEAD to $sha with a --soft (keep index + working tree) or --mixed
     * (keep working tree, reset index) reset. No --hard: never discard the
     * working tree from here.
     *
     * @param 'soft'|'mixed'                   $mode
     * @param callable(Outcome, string): void $onDone (ok, first stderr line on failure)
     */
    public function resetTo(string $sha, string $mode, callable $onDone): void
    {
        $this->runner->runWithErrors(['reset', '--'.$mode, $sha], $onDone);
    }

    /**
     * The files a commit touched, with their status letters.
     *
     * @param callable(list<array{status: string, path: string}>): void $onDone
     */
    public function commitFiles(string $sha, callable $onDone): void
    {
        $this->runner->run(['show', '-z', '--name-status', '--format=', $sha], static fn (string $out) => $onDone(LogParser::parseNameStatus($out)));
    }

    /**
     * One file's patch within a commit, for the read-only history diff.
     *
     * @param callable(string): void $onDone     receives the raw diff
     * @param WhitespaceMode         $whitespace {@see WhitespaceMode::Hidden} drops whitespace-only changes (-w)
     */
    public function commitFileDiff(string $sha, string $relPath, callable $onDone, WhitespaceMode $whitespace = WhitespaceMode::Shown): void
    {
        $this->runner->run(['show', '--no-color', '--format=', ...(WhitespaceMode::Hidden === $whitespace ? ['-w'] : []), $sha, '--', $relPath], $onDone);
    }

    public function cancelAll(): void
    {
        $this->runner->cancelAll();
    }

    /**
     * The signing/verification flags spliced into a commit, from the git
     * settings (none when unconfigured, e.g. a directly-constructed GitInfo).
     *
     * @return list<string>
     */
    private function commitFlags(): array
    {
        if (null === $this->gitSettings) {
            return [];
        }
        // Explicit --no-gpg-sign so "off" overrides a repo's commit.gpgsign=true.
        $flags = [$this->gitSettings->signCommits() ? '-S' : '--no-gpg-sign'];
        if ($this->gitSettings->noVerify()) {
            $flags[] = '--no-verify';
        }
        if ($this->gitSettings->signoff()) {
            $flags[] = '--signoff';
        }

        return $flags;
    }
}
