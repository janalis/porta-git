<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Process;

use Porta\GitPlugin\GitInfo;
use Porta\Support\AsyncProcess;
use Porta\Support\Outcome;
use Porta\Support\Text;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Runs `git` subprocesses for the working tree at $rootDir, on the shared event
 * loop, and tracks the ones still in flight.
 *
 * It holds no mutable state of its own — the in-flight set lives in the
 * {@see ProcessLane} it is given, so that a set of processes can be cancelled
 * and counted as a group by something that does not know what is in it.
 * {@see GitInfo}'s thirty-odd porcelain verbs are, once this is out of the way,
 * stateless wrappers that build an argv and parse an answer, which is the
 * reason for the split.
 */
final class GitProcessRunner
{
    private readonly ?string $git;
    private readonly bool $available;
    private readonly bool $repository;

    private readonly ProcessLane $lane;

    /**
     * $lane is optional and TRAILING so every direct construction — the tests,
     * and anything that only wants its own git — keeps working unchanged. What
     * passes one is `plugin.php`, so that shutdown and the completion barrier
     * can reach these processes through {@see GitProcesses}.
     */
    public function __construct(
        private readonly string $rootDir,
        ?string $gitPath = null,
        ?ProcessLane $lane = null,
    ) {
        $this->lane = $lane ?? new ProcessLane();
        $this->git = self::findGit($gitPath);
        // file_exists, not is_dir: a linked worktree's and a submodule's .git is a
        // FILE holding `gitdir: …`. is_dir left the whole feature silently dead
        // in both — git ran nowhere and the view said "not a repository".
        $this->repository = file_exists($rootDir.'/.git');
        $this->available = null !== $this->git && $this->repository;
    }

    /**
     * The resolved `git` binary: the explicitly configured path when one is set,
     * else whatever discovery found, else null. Exposed so the path-resolution
     * rule is testable without reflecting on a private field.
     */
    public function binary(): ?string
    {
        return $this->git;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Whether the project is a repository at all — deliberately NOT
     * {@see isAvailable}, which also requires the binary.
     *
     * The two states want different chrome. Outside a repository the git verbs
     * are noise and are not registered. INSIDE one with no `git` on PATH they
     * stay, and answer with a flash saying so: hiding them there would leave a
     * user who is plainly in a repository with no way to find out why git went
     * missing.
     */
    public function isRepository(): bool
    {
        return $this->repository;
    }

    public function cancelAll(): void
    {
        $this->lane->cancelAll();
    }

    /**
     * @param list<string>            $args
     * @param callable(string): void  $onDone receives stdout
     */
    public function run(array $args, callable $onDone): void
    {
        $this->runStatus($args, static fn (int $code, string $out) => $onDone($out));
    }

    /**
     * @param list<string>                 $args
     * @param callable(int, string): void  $onDone receives (exit code, stdout)
     * @param string|null                  $stdin  payload piped to the command
     */
    public function runStatus(array $args, callable $onDone, ?string $stdin = null): void
    {
        // No git, or no repository: answer as a failed run with no output. Every
        // caller already handles that shape — the Outcome verbs read the code,
        // and every parser returns its empty value for an empty string — so this
        // is the whole availability rule, in the one place that knows it.
        if (!$this->available) {
            $empty = '';
            $onDone(1, $empty);

            return;
        }
        /** @var list<string> $command */
        // --no-optional-locks: the porcelain readers (status/diff) must never
        // take the opportunistic index lock — under load it collides with a
        // concurrent add/commit's MANDATORY index.lock and fails the mutation.
        $command = [$this->git, '--no-optional-locks', '-C', $this->rootDir, ...$args];
        $started = $this->lane->start(
            new AsyncProcess($command, $this->rootDir, $stdin),
            static function (int $code, string $out) use ($onDone): void {
                $onDone($code, $out);
            },
        );
        if (!$started) {
            // @codeCoverageIgnoreStart
            $empty = '';
            $onDone(1, $empty);

            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Like runStatus, but with stderr captured — for verbs whose failure
     * message the user must see verbatim (checkout, branch creation).
     *
     * @param list<string>                 $args
     * @param callable(Outcome, string): void $onDone (ok, trimmed first stderr line)
     */
    public function runWithErrors(array $args, callable $onDone): void
    {
        if (!$this->available) {
            $reason = 'git is not available';
            $onDone(Outcome::Failed, $reason);

            return;
        }
        /** @var list<string> $command */
        // --no-optional-locks: the porcelain readers (status/diff) must never
        // take the opportunistic index lock — under load it collides with a
        // concurrent add/commit's MANDATORY index.lock and fails the mutation.
        $command = [$this->git, '--no-optional-locks', '-C', $this->rootDir, ...$args];
        $started = $this->lane->start(
            new AsyncProcess($command, $this->rootDir, captureStderr: true),
            static function (int $code, string $out, AsyncProcess $process) use ($onDone): void {
                $onDone(Outcome::of(0 === $code), Text::firstLine($process->errorOutput()));
            },
        );
        if (!$started) {
            // @codeCoverageIgnoreStart
            $reason = 'could not start git';
            $onDone(Outcome::Failed, $reason);

            // @codeCoverageIgnoreEnd
        }
    }

    private static function findGit(?string $gitPath): ?string
    {
        // An explicit path (the "Git executable path" setting) wins over discovery.
        if (null !== $gitPath && '' !== $gitPath) {
            return $gitPath;
        }

        return new ExecutableFinder()->find('git');
    }
}
