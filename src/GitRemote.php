<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\Support\AsyncProcess;
use Porta\Support\Outcome;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Asynchronous git remote operations (fetch / pull / push). Separate from the
 * local read/index client ({@see GitInfo}) because remote commands have
 * different needs: stderr carries everything human-relevant (progress, auth
 * failures, "not possible to fast-forward"), credential prompts must be
 * forced non-interactive, and only one remote operation runs at a time.
 *
 * `GIT_TERMINAL_PROMPT=0` turns a would-be credential prompt into a clean
 * non-zero exit with the reason on stderr. Known limitation: ssh itself can
 * still prompt for an agent-less key passphrase; forcing BatchMode would
 * break ssh-agent users, so it stays unset — an agent is expected.
 */
final class GitRemote
{
    private readonly bool $available;

    private readonly ?string $git;

    /** @var array<int, AsyncProcess> in-flight processes */
    private array $running = [];

    private int $nextKey = 0;

    public function __construct(
        private readonly string $rootDir,
        ?string $gitPath = null,
    ) {
        // An explicit path (the "Git executable path" setting) wins over discovery.
        $this->git = null !== $gitPath && '' !== $gitPath ? $gitPath : new ExecutableFinder()->find('git');
        // file_exists, not is_dir: a linked worktree's and a submodule's .git is a
        // FILE holding `gitdir: …`. is_dir left the whole feature silently dead
        // in both — git ran nowhere and the view said "not a repository".
        $this->available = null !== $this->git && file_exists($rootDir.'/.git');
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /** One remote operation at a time — a second invocation must be refused. */
    public function isBusy(): bool
    {
        return [] !== $this->running;
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
        foreach ($this->running as $process) {
            $process->cancel();
        }
        $this->running = [];
    }

    /**
     * @param list<string>                 $args
     * @param callable(Outcome, string): void $onDone
     */
    private function run(array $args, callable $onDone): void
    {
        if (!$this->available) {
            $reason = 'git is not available';
            $onDone(Outcome::Failed, $reason);

            return;
        }
        /** @var list<string> $command */
        $command = [$this->git, '-C', $this->rootDir, ...$args];
        $env = array_merge(getenv(), ['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C']);
        /** @var array<string, string> $env */
        $key = $this->nextKey++;
        $process = new AsyncProcess($command, $this->rootDir, captureStderr: true, env: $env);
        $started = $process->start(function (int $code, string $out) use ($key, $process, $onDone): void {
            unset($this->running[$key]);
            $onDone(Outcome::of(0 === $code), trim(trim($process->errorOutput())."\n".trim($out)));
        });
        if (!$started) {
            // @codeCoverageIgnoreStart
            $reason = 'could not start git';
            $onDone(Outcome::Failed, $reason);

            return;

            // @codeCoverageIgnoreEnd
        }
        $this->running[$key] = $process;
    }
}
