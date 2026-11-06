<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\DiffPlugin\HunkParser;
use Porta\Editor\ConflictMarkers;
use Porta\GitPlugin\Command\CommitMode;
use Porta\GitPlugin\Diff\WhitespaceMode;
use Porta\GitPlugin\Parse\BranchParser;
use Porta\GitPlugin\Parse\LogParser;
use Porta\GitPlugin\Parse\OperationReader;
use Porta\GitPlugin\Parse\StatusParser;
use Porta\GitPlugin\Parse\WorktreeParser;
use Porta\GitPlugin\Process\GitProcesses;
use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\GitPlugin\Process\ProcessLane;
use Porta\GitPlugin\Setting\GitSettings;
use Porta\Support\Outcome;
use Porta\Support\Text;

/**
 * Asynchronous git integration: current branch name and per-line change
 * marks (added / modified / deleted) computed from `git diff` against HEAD.
 *
 * Everything is best-effort and silently disabled when the project is not a
 * git working tree or the `git` binary is unavailable.
 */
final readonly class GitInfo
{
    private GitProcessRunner $runner;

    private string $rootDir;

    /**
     * $lane is optional and TRAILING so direct construction keeps working;
     * `porta.php` passes {@see GitProcesses::$general}, which is what puts
     * these processes inside the one shutdown and the one completion barrier.
     */
    /** @param null|(\Closure(): ?string)|string $gitPath read per call when a closure, so the setting is live */
    public function __construct(
        string $rootDir,
        private ?GitSettings $gitSettings = null,
        \Closure|string|null $gitPath = null,
        ?ProcessLane $lane = null,
    ) {
        $this->runner = new GitProcessRunner($rootDir, $gitPath, $lane);
        $this->rootDir = $rootDir;
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
            $branch = \trim($out);
            $onDone('' === $branch || 'HEAD' === $branch ? null : $branch);
        });
    }

    /**
     * How far the branch has drifted from its upstream: the commits only here
     * (ahead) and the ones only there (behind) — null when it has no upstream,
     * which git reports as a failure.
     *
     * @param callable(?Tracking): void $onDone
     */
    public function tracking(callable $onDone): void
    {
        $this->runner->runStatus(['rev-list', '--left-right', '--count', 'HEAD...@{upstream}'], static function (int $code, string $out) use ($onDone): void {
            $counts = [];
            $onDone(0 === $code && 1 === \preg_match('/^(\d+)\s+(\d+)$/', \trim($out), $counts) ? new Tracking((int) $counts[1], (int) $counts[2]) : null);
        });
    }

    /**
     * The remote $branch pushes to and pulls from — `origin`, usually — or
     * null for a branch never published, or one tracking a LOCAL branch,
     * which git spells `.`: there is no remote to name.
     *
     * @param callable(?string): void $onDone
     */
    public function remoteOf(string $branch, callable $onDone): void
    {
        $this->runner->runStatus(['config', '--get', 'branch.'.$branch.'.remote'], static function (int $code, string $out) use ($onDone): void {
            $remote = \trim($out);
            $onDone(0 === $code && !\in_array($remote, ['', '.'], strict: true) ? $remote : null);
        });
    }

    /**
     * How many lines a commit added and removed, over all its files — the
     * `+3 −1` beside its header — or null when git could not say.
     *
     * @param callable(?DiffStat): void $onDone
     */
    public function commitStat(string $sha, callable $onDone): void
    {
        $this->runner->runStatus(['show', '--shortstat', '--format=', $sha], static function (int $code, string $out) use ($onDone): void {
            $added = [];
            $deleted = [];
            \preg_match('/(\d+) insertion/', $out, $added);
            \preg_match('/(\d+) deletion/', $out, $deleted);
            $onDone(0 === $code ? new DiffStat((int) ($added[1] ?? 0), (int) ($deleted[1] ?? 0)) : null);
        });
    }

    /**
     * Where the repository lives, all absolute: the working tree's top
     * folder, git's COMMON directory (the main worktree's `.git`, which names
     * the repository), and this worktree's own FETCH_HEAD — per worktree, so
     * a linked one's is under `.git/worktrees/<name>/`. All null when git
     * could not say.
     *
     * @param callable(?Locations): void $onDone
     */
    public function locations(callable $onDone): void
    {
        $this->runner->runStatus(['rev-parse', '--path-format=absolute', '--show-toplevel', '--git-common-dir', '--git-path', 'FETCH_HEAD'], static function (int $code, string $out) use (
            $onDone,
        ): void {
            $lines = \explode("\n", \trim($out));
            $onDone(0 === $code && 3 === \count($lines) ? new Locations($lines[0], $lines[1], $lines[2]) : null);
        });
    }

    /**
     * Where git keeps the files that say an operation is in progress — this
     * worktree's own, which `--git-path` resolves for a linked one — absolute,
     * by name. Asked once: they do not move while the IDE runs, and
     * {@see OperationReader} needs no process to read them.
     *
     * @param callable(array<string, string>|null): void $onDone each of {@see OperationReader::FILES} => its path, or null
     */
    public function operationPaths(callable $onDone): void
    {
        $args = ['rev-parse', '--path-format=absolute'];
        foreach (OperationReader::FILES as $file) {
            $args[] = '--git-path';
            $args[] = $file;
        }
        $this->runner->runStatus($args, static function (int $code, string $out) use ($onDone): void {
            $paths = \explode("\n", \trim($out));
            $onDone(0 === $code && \count($paths) === \count(OperationReader::FILES) ? \array_combine(OperationReader::FILES, $paths) : null);
        });
    }

    /**
     * How many paths are still conflicted.
     *
     * @param callable(int): void $onDone
     */
    public function conflictCount(callable $onDone): void
    {
        $this->runner->run(['diff', '--name-only', '--diff-filter=U', '-z'], static fn (string $out) => $onDone(\count(\array_filter(\explode("\0", $out)))));
    }

    /**
     * The conflicted files under the project that still hold a conflict
     * marker, project-relative: what staging them — marking them resolved —
     * would carry into the commit.
     *
     * @param callable(list<string>): void $onDone
     */
    public function conflictsLeft(callable $onDone): void
    {
        $root = $this->rootDir;
        $this->runner->run(['diff', '--name-only', '--relative', '--diff-filter=U', '-z'], static function (string $out) use ($onDone, $root): void {
            $paths = \array_filter(
                \explode("\0", $out),
                static fn (string $path): bool => '' !== $path && \is_file($root.'/'.$path) && ConflictMarkers::unresolved(\explode("\n", (string) \file_get_contents($root.'/'.$path))),
            );
            $onDone(\array_values($paths));
        });
    }

    /**
     * Continue, skip or abort $operation. Unattended: git takes its own
     * message where it would open an editor, as nobody is at one.
     *
     * @param callable(Outcome, string): void $onDone (ok, first stderr line on failure)
     */
    public function resumeOperation(GitOperation $operation, OperationStep $step, callable $onDone): void
    {
        $this->runner->runUnattended([$operation->command(), '--'.$step->value], $onDone);
    }

    /**
     * Compute change marks for a file relative to HEAD.
     *
     * @param callable(array<int, string>): void $onDone 0-based line => 'added'|'modified'|'deleted'
     */
    public function diff(string $absPath, callable $onDone): void
    {
        $this->runner->run(['diff', '--no-color', '--no-ext-diff', '--unified=0', 'HEAD', '--', $absPath], static function (string $out) use ($onDone): void {
            $onDone(HunkParser::parse($out));
        });
    }

    /**
     * The file's diff against HEAD with no context — one hunk per change, the
     * same hunks {@see diff()} marks — raw, for showing and reverting one.
     *
     * @param callable(string): void $onDone receives the raw diff
     */
    public function headDiff(string $absPath, callable $onDone): void
    {
        $this->runner->run(['diff', '--no-color', '--no-ext-diff', '--unified=0', 'HEAD', '--', $absPath], $onDone);
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
        $this->runner->run([
            'diff',
            '--no-color',
            '--no-ext-diff',
            '--cached',
            ...(null === $context ? [] : ['-U'.$context]),
            ...(WhitespaceMode::Hidden === $whitespace ? ['-w'] : []),
            '--',
            $absPath,
        ], $onDone);
    }

    /**
     * A conflicted file against OUR side (`git diff --ours`): the markers and
     * their side as additions. A plain `git diff` of an unmerged path is a
     * combined `diff --cc`, which the diff pane does not read — it showed
     * "No changes" for the one file a merge is about.
     *
     * @param callable(string): void $onDone receives the raw diff
     * @param int|null               $context surrounding lines per hunk (null = git's default)
     */
    public function conflictFileDiff(string $absPath, callable $onDone, ?int $context = null): void
    {
        $this->runner->run(['diff', '--no-color', '--no-ext-diff', '--ours', ...(null === $context ? [] : ['-U'.$context]), '--', $absPath], $onDone);
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
        $this->runner->run(['diff', '--no-color', '--no-ext-diff', ...(null === $context ? [] : ['-U'.$context]), ...(WhitespaceMode::Hidden === $whitespace ? ['-w'] : []), '--', $absPath], $onDone);
    }

    /**
     * A whole-file "diff" for an untracked file (everything is an addition).
     * `--no-index` exits 1 when the files differ, which is the success case.
     *
     * @param callable(string): void $onDone receives the raw diff
     */
    public function untrackedFileDiff(string $absPath, callable $onDone): void
    {
        $this->runner->runStatus(['diff', '--no-color', '--no-ext-diff', '--no-index', '--', '/dev/null', $absPath], static fn (int $code, string $out) => $onDone($out));
    }

    /**
     * Compare two arbitrary files — neither has to be in the repository, or
     * even under it. `--no-index` is git's own two-path mode, the same one
     * {@see untrackedFileDiff()} uses, and it exits 1 when the files differ,
     * which is the interesting case.
     *
     * @param callable(string): void $onDone receives the raw diff, header and all: the
     *                                       header names both files, which for a pair the
     *                                       user picked is the one thing the rows do not say
     */
    public function compareFiles(string $left, string $right, callable $onDone, ?int $context = null): void
    {
        $this->runner->runStatus(['diff', '--no-color', '--no-ext-diff', '--no-index', ...(null === $context ? [] : ['-U'.$context]), '--', $left, $right], static fn (
            int $code,
            string $out,
        ) => $onDone($out));
    }

    /**
     * Take a file's changes out of the index (`git restore --staged`).
     *
     * @param callable(Outcome): void $onDone
     */
    public function unstageFile(string $absPath, callable $onDone, ?string $renamedFromAbs = null): void
    {
        // A staged rename is the new name AND the old one's deletion: both
        // sides go, or the deletion stays staged and the next commit records
        // only that.
        $paths = null === $renamedFromAbs ? [$absPath] : [$absPath, $renamedFromAbs];
        $this->runner->runStatus(['restore', '--staged', '--', ...$paths], static fn (int $code) => $onDone(Outcome::of(0 === $code)));
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
     * Throw away a STAGED change too, back to HEAD in the index and the
     * worktree: `restore` alone copies the index over the worktree, which for
     * a staged change is the change itself — "Discarded", and nothing moved.
     *
     * @param list<string>            $absPaths
     * @param callable(Outcome): void $onDone
     */
    public function discardToHead(array $absPaths, callable $onDone): void
    {
        $this->runner->runStatus(['restore', '--source=HEAD', '--staged', '--worktree', '--', ...$absPaths], static fn (int $code) => $onDone(Outcome::of(0 === $code)));
    }

    /**
     * Throw away a file that is new in the index: out of the index and off
     * the disk, as discarding an untracked file deletes it.
     *
     * @param callable(Outcome): void $onDone
     */
    public function discardAdded(string $absPath, callable $onDone): void
    {
        $this->runner->runStatus(['rm', '-f', '--', $absPath], static fn (int $code) => $onDone(Outcome::of(0 === $code)));
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
    /**
     * Stage every change in the working tree, untracked files included.
     *
     * @param callable(Outcome): void $onDone
     */
    public function stageAll(callable $onDone): void
    {
        $this->runner->runStatus(['add', '--all'], static fn (int $code) => $onDone(Outcome::of(0 === $code)));
    }

    /**
     * Unstage everything: the index back to HEAD. On a branch with no commit
     * yet `reset` empties the index instead (asserted against a real
     * repository), so one command covers both.
     *
     * @param callable(Outcome): void $onDone
     */
    public function unstageAll(callable $onDone): void
    {
        $this->runner->runStatus(['reset', '--quiet'], static fn (int $code) => $onDone(Outcome::of(0 === $code)));
    }

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
        // What a refusal says goes to stderr — a hook's output, gpg, the index
        // lock, unmerged files — and stdout alone left `Commit failed: ` with
        // nothing after it.
        $this->runner->runCapturing($args, static fn (Outcome $ok, string $out, string $errors) => $onDone($ok, $ok->isOk() ? \trim($out) : self::refusal($errors, $out)));
    }

    /**
     * Why git refused, in one line: its own `error:` or `fatal:` line when it
     * gave one, else the last line a hook printed, else the last line of
     * stdout — "nothing to commit, working tree clean", where the first is
     * only "On branch main".
     */
    private static function refusal(string $errors, string $out): string
    {
        $lines = self::saidLines($errors);
        $own = \array_find($lines, static fn (string $line): bool => 1 === \preg_match('/^(error|fatal):/', $line));

        return $own ?? \array_last($lines) ?? \array_last(self::saidLines($out)) ?? '';
    }

    /** @return list<string> the non-empty lines of $text, git's `hint:` lines left out */
    private static function saidLines(string $text): array
    {
        return \array_values(\array_filter(\array_map(\trim(...), \explode("\n", $text)), static fn (string $line): bool => '' !== $line && !\str_starts_with($line, 'hint:')));
    }

    /**
     * The last commit's full message, for prefilling an amend.
     *
     * @param callable(string): void $onDone
     */
    public function lastCommitMessage(callable $onDone): void
    {
        $this->runner->run(['log', '-1', '--format=%B'], static fn (string $out) => $onDone(\trim($out)));
    }

    /**
     * Whether the branch has a commit yet — a fresh `git init` has none, and
     * there is nothing to amend until it does.
     *
     * @param callable(bool): void $onDone
     */
    public function hasHead(callable $onDone): void
    {
        $this->runner->runStatus(['rev-parse', '--verify', '--quiet', 'HEAD'], static fn (int $code) => $onDone(0 === $code));
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
            $onDone(Outcome::of(0 === $code), Text::firstLine($out));
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
        $this->runner->runWithErrors(['checkout', '-b', BranchParser::shortName($remoteBranch), '--track', $remoteBranch], $onDone);
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
        $this->runner->run(['show', '--no-color', '--no-ext-diff', '--format=', ...(WhitespaceMode::Hidden === $whitespace ? ['-w'] : []), $sha, '--', $relPath], $onDone);
    }

    /**
     * The repository's worktrees, the main one first — or null when git
     * could not list them, with its reason.
     *
     * `-z` arrived in git 2.36, and an older git refuses the switch outright.
     * It is asked again without, then: only a path with a newline in it,
     * which `-z` exists for, reads wrong that way.
     *
     * @param callable(list<Worktree>|null, string): void $onDone
     */
    public function worktrees(callable $onDone): void
    {
        $this->runner->runCapturing(['worktree', 'list', '--porcelain', '-z'], function (Outcome $ok, string $out, string $errors) use ($onDone): void {
            if ($ok->isOk()) {
                $none = '';
                $onDone(WorktreeParser::parse($out), $none);

                return;
            }
            $this->runner->runCapturing(['worktree', 'list', '--porcelain'], static function (Outcome $ok, string $out, string $errors) use ($onDone): void {
                $none = '';
                $ok->isOk() ? $onDone(WorktreeParser::parse($out, "\n"), $none) : $onDone(null, self::reason($errors));
            });
        });
    }

    /**
     * Add a worktree at $path: on a new branch $newBranch (from $commitish,
     * or HEAD), or with $commitish checked out — GitHub Desktop's three
     * shapes, a new branch, a local one, and a remote one tracked locally.
     *
     * @param callable(Outcome, string): void $onDone (ok, git's reason on failure)
     */
    public function addWorktree(string $path, ?string $newBranch, ?string $commitish, callable $onDone): void
    {
        $this->changeWorktrees(['add', ...(null === $newBranch ? [] : ['-b', $newBranch]), '--', $path, ...(null === $commitish ? [] : [$commitish])], $onDone);
    }

    /**
     * Delete a worktree's folder and git's record of it. git refuses one with
     * changes in it, which is the caller's to ask about.
     *
     * @param callable(Outcome, string): void $onDone
     */
    public function removeWorktree(string $path, callable $onDone): void
    {
        $this->changeWorktrees(['remove', '--', $path], $onDone);
    }

    /**
     * Delete a worktree and the changes in it, once someone has said so.
     *
     * @param callable(Outcome, string): void $onDone
     */
    public function forceRemoveWorktree(string $path, callable $onDone): void
    {
        $this->changeWorktrees(['remove', '--force', '--', $path], $onDone);
    }

    /** @param callable(Outcome, string): void $onDone */
    public function moveWorktree(string $from, string $to, callable $onDone): void
    {
        $this->changeWorktrees(['move', '--', $from, $to], $onDone);
    }

    /** @param callable(Outcome, string): void $onDone */
    public function lockWorktree(string $path, string $reason, callable $onDone): void
    {
        $this->changeWorktrees(['lock', ...('' === $reason ? [] : ['--reason', $reason]), '--', $path], $onDone);
    }

    /** @param callable(Outcome, string): void $onDone */
    public function unlockWorktree(string $path, callable $onDone): void
    {
        $this->changeWorktrees(['unlock', '--', $path], $onDone);
    }

    /**
     * Forget the worktrees whose folders are gone.
     *
     * @param callable(Outcome, string): void $onDone
     */
    public function pruneWorktrees(callable $onDone): void
    {
        $this->changeWorktrees(['prune'], $onDone);
    }

    public function cancelAll(): void
    {
        $this->runner->cancelAll();
    }

    /**
     * @param list<string>                    $args after `worktree`
     * @param callable(Outcome, string): void $onDone
     */
    private function changeWorktrees(array $args, callable $onDone): void
    {
        $this->runner->runCapturing(['worktree', ...$args], static fn (Outcome $ok, string $out, string $errors) => $onDone($ok, self::reason($errors)));
    }

    /**
     * What went wrong, from git's stderr: its last `fatal:` or `error:` line.
     * The first line is the wrong one here — `worktree add` opens with
     * "Preparing worktree (new branch 'x')" and only then says why it failed.
     */
    private static function reason(string $errors): string
    {
        $lines = \explode("\n", \trim($errors));
        $said = \array_values(\array_filter($lines, static fn (string $line): bool => \str_starts_with($line, 'fatal: ') || \str_starts_with($line, 'error: ')));

        return [] === $said ? Text::firstLine($errors) : \rtrim($said[\count($said) - 1], characters: "\r");
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
        $flags = [$this->gitSettings->signCommits ? '-S' : '--no-gpg-sign'];
        if ($this->gitSettings->noVerify) {
            $flags[] = '--no-verify';
        }
        if ($this->gitSettings->signoff) {
            $flags[] = '--signoff';
        }

        return $flags;
    }
}
