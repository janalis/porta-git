<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Process;

use Porta\Config\WorkspaceTrust;
use Porta\GitPlugin\GitInfo;
use Porta\Support\AsyncProcess;
use Porta\Support\Outcome;

/**
 * Asynchronous git remote operations (fetch / pull / push). Separate from the
 * local read/index client ({@see GitInfo}) because remote commands have
 * different needs: stderr carries everything human-relevant (progress, auth
 * failures, "not possible to fast-forward"), credential prompts must be
 * forced non-interactive, and only one remote operation runs at a time.
 *
 * Nothing it runs may ask the terminal anything — Porta is drawing on it —
 * and nothing may wait forever:
 *
 * - `GIT_TERMINAL_PROMPT=0` turns git's own credential prompt into a clean
 *   failure, with the reason on stderr.
 * - ssh asks through `SSH_ASKPASS`, forced, and the program it is given
 *   says no: a passphrase or an unknown host key is a failure, not text
 *   written over the screen and keys stolen from it. An agent still signs.
 * - An HTTPS transfer that stalls ends after {@see STALL_SECONDS}; and any
 *   operation, ssh's black-holed connection included, after {@see TIMEOUT}.
 *
 * What the user set in the environment wins over each.
 */
final readonly class GitRemote
{
    /** Seconds an operation may take at all: long enough for a large fetch, short of forever. */
    public const float TIMEOUT = 900.0;

    /** Seconds an HTTPS transfer may move less than a kilobyte a second. */
    private const string STALL_SECONDS = '30';

    private bool $repository;

    private bool $trusted;

    private ProcessLane $lane;

    /**
     * The lane is optional and TRAILING so direct construction keeps working;
     * `plugin.php` passes {@see GitProcesses::$remote}, which is a lane of its
     * own for one reason: {@see cancelOperation()} must stop a hung fetch
     * without stopping the status poll, and {@see isBusy()} must mean "a remote
     * operation is running" rather than "git is running".
     *
     * @param null|(\Closure(): ?string)|string $gitPath the configured `git` path, or a CLOSURE read per
     *                                                   call so a Settings edit applies without a restart
     */
    public function __construct(
        private string $rootDir,
        private \Closure|string|null $gitPath = null,
        ?ProcessLane $lane = null,
    ) {
        $this->lane = $lane ?? new ProcessLane();
        // file_exists, not is_dir: a linked worktree's and a submodule's .git is a
        // FILE holding `gitdir: …`. is_dir left the whole feature silently dead
        // in both — git ran nowhere and the view said "not a repository".
        $this->repository = \file_exists($rootDir.'/.git');
        $this->trusted = WorkspaceTrust::fromEnvironment()->isTrusted($rootDir);
    }

    /** Resolved per call, so an edit to the `path` setting reaches the next spawn. */
    private function binary(): ?string
    {
        return GitBinary::resolve($this->gitPath instanceof \Closure ? ($this->gitPath)() : $this->gitPath);
    }

    public function isAvailable(): bool
    {
        return null !== $this->binary() && $this->repository;
    }

    /** One remote operation at a time — a second invocation must be refused. */
    public function isBusy(): bool
    {
        return 0 !== $this->lane->inFlight();
    }

    /** @param callable(Outcome, string): void $onDone (ok, trimmed stderr+stdout — stderr first: it's where git talks) */
    public function fetch(callable $onDone): void
    {
        $this->run(['fetch', '--prune'], $onDone);
    }

    /**
     * `--ff-only`: either fast-forwards or fails cleanly with git's message.
     * A merging/rebasing pull creates conflict state the git view can't drive
     * yet; a `pull.rebase`-respecting mode is a future setting.
     *
     * @param callable(Outcome, string): void $onDone
     */
    public function pull(callable $onDone): void
    {
        $this->run(['pull', '--ff-only'], $onDone);
    }

    /** Integrate the diverged remote branch by rebasing local commits on top. @param callable(Outcome, string): void $onDone */
    public function pullRebase(callable $onDone): void
    {
        $this->run(['pull', '--rebase'], $onDone);
    }

    /** Integrate the diverged remote branch with a merge commit. @param callable(Outcome, string): void $onDone */
    public function pullMerge(callable $onDone): void
    {
        $this->run(['pull', '--no-rebase', '--no-edit'], $onDone);
    }

    /** @param callable(Outcome, string): void $onDone */
    public function push(callable $onDone): void
    {
        $this->run(['push'], $onDone);
    }

    /** @param callable(Outcome, string): void $onDone */
    public function pushSetUpstream(callable $onDone): void
    {
        $this->run(['push', '-u', 'origin', 'HEAD'], $onDone);
    }

    public function cancelAll(): void
    {
        $this->lane->cancelAll();
    }

    /**
     * @param list<string>                 $args
     * @param callable(Outcome, string): void $onDone
     */
    private function run(array $args, callable $onDone): void
    {
        $git = $this->binary();
        if (null === $git || !$this->repository) {
            $reason = 'git is not available';
            $onDone(Outcome::Failed, $reason);

            return;
        }
        // An untrusted repository's config names no program a pull or a push
        // runs — a hook, a credential helper, an ssh command, a filter on the
        // checkout — the status poll's rule. Read per operation: they are rare.
        // A remote's `uploadpack` and `receivepack` are the program a local
        // or ssh transfer starts, and git takes the first one it is given —
        // so no `-c` can replace them, and the verb names git's own instead.
        /** @var list<string> $command */
        $command = $this->trusted
            ? [$git, '-C', $this->rootDir, ...$args]
            : [
                $git,
                ...UntrustedConfig::arguments($git, $this->rootDir),
                '-C',
                $this->rootDir,
                $args[0],
                'push' === $args[0] ? '--receive-pack=git-receive-pack' : '--upload-pack=git-upload-pack',
                ...\array_slice($args, offset: 1),
            ];
        $env = [
            'SSH_ASKPASS_REQUIRE' => 'force',
            'SSH_ASKPASS' => 'false',
            'GIT_HTTP_LOW_SPEED_LIMIT' => '1000',
            'GIT_HTTP_LOW_SPEED_TIME' => self::STALL_SECONDS,
            ...\getenv(),
            ...($this->trusted ? [] : ['GIT_ATTR_SOURCE' => UntrustedConfig::EMPTY_TREE]),
            'GIT_TERMINAL_PROMPT' => '0',
            'LC_ALL' => 'C',
        ];
        /** @var array<string, string> $env */
        $started = $this->lane->start(
            new AsyncProcess($command, $this->rootDir, captureStderr: true, env: $env, timeout: self::TIMEOUT),
            static function (int $code, string $out, AsyncProcess $process) use ($onDone): void {
                $onDone(Outcome::of(0 === $code), \trim(\trim($process->errorOutput())."\n".\trim($out)));
            },
        );
        if (!$started) {
            // @codeCoverageIgnoreStart
            $reason = 'could not start git';
            $onDone(Outcome::Failed, $reason);

            // @codeCoverageIgnoreEnd
        }
    }
}
