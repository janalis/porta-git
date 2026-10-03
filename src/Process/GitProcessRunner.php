<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Process;

use Porta\Config\WorkspaceTrust;
use Porta\GitPlugin\GitInfo;
use Porta\Support\AsyncProcess;
use Porta\Support\Outcome;
use Porta\Support\Text;

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
final readonly class GitProcessRunner
{
    private bool $repository;

    /** git's well-known empty tree (SHA-1): an attribute source that says nothing. */
    private const string EMPTY_TREE = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

    /** @var list<string> config overrides for a repository not trusted to run code */
    private array $untrusted;

    private ProcessLane $lane;

    /**
     * $lane is optional and TRAILING so every direct construction — the tests,
     * and anything that only wants its own git — keeps working unchanged. What
     * passes one is `plugin.php`, so that shutdown and the completion barrier
     * can reach these processes through {@see GitProcesses}.
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
        // An untrusted repository's own config is not obeyed where it would run
        // something: `core.fsmonitor` names a program git runs on every status,
        // and the status poll runs with no one asking. A clone never carries
        // .git/config, but a tarball or a shared folder does.
        //
        // Nor its attributes: `.gitattributes` names filter and textconv
        // drivers its config defines (`filter.x.clean = …`), and a status
        // re-hashing a touched file runs the clean filter. Attributes read from
        // the empty tree are none at all — a git older than 2.40 ignores the
        // variable, and the fsmonitor half still holds there.
        $this->untrusted = WorkspaceTrust::fromEnvironment()->isTrusted($rootDir) ? [] : ['-c', 'core.fsmonitor=false'];
    }

    /**
     * The resolved `git` binary: the explicitly configured path when one is set,
     * else whatever discovery found, else null. Exposed so the path-resolution
     * rule is testable without reflecting on a private field.
     *
     * Resolved PER CALL rather than cached, so editing the `path` setting takes
     * effect on the next spawn — the AbstractToolPlugin::binary() idiom. The
     * cost is an ExecutableFinder walk only while the setting is empty, which a
     * process spawn dwarfs.
     */
    public function binary(): ?string
    {
        return GitBinary::resolve($this->gitPath instanceof \Closure ? ($this->gitPath)() : $this->gitPath);
    }

    /**
     * The child's environment: inherited as is, or — for an untrusted
     * repository — with its attributes read from the empty tree. Per spawn, so
     * a PATH changed since boot is the one git sees.
     *
     * @return array<string, string>|null
     */
    private function environment(): ?array
    {
        return [] === $this->untrusted ? null : [...\getenv(), 'GIT_ATTR_SOURCE' => self::EMPTY_TREE];
    }

    public function isAvailable(): bool
    {
        return null !== $this->binary() && $this->repository;
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
        $git = $this->binary();
        if (null === $git || !$this->repository) {
            $empty = '';
            $onDone(1, $empty);

            return;
        }
        /** @var list<string> $command */
        // --no-optional-locks: the porcelain readers (status/diff) must never
        // take the opportunistic index lock — under load it collides with a
        // concurrent add/commit's MANDATORY index.lock and fails the mutation.
        $command = [$git, ...$this->untrusted, '--no-optional-locks', '-C', $this->rootDir, ...$args];
        $started = $this->lane->start(
            new AsyncProcess($command, $this->rootDir, $stdin, env: $this->environment()),
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
        $this->runCapturing($args, static fn (Outcome $ok, string $out, string $errors) => $onDone($ok, Text::firstLine($errors)));
    }

    /**
     * Both streams, whole: for a verb that answers on stdout and whose
     * failure has to be told apart, and for one whose first stderr line is
     * progress rather than the reason (`worktree add` says "Preparing
     * worktree" before it says what went wrong).
     *
     * @param list<string>                            $args
     * @param callable(Outcome, string, string): void $onDone (ok, stdout, stderr)
     */
    public function runCapturing(array $args, callable $onDone): void
    {
        $git = $this->binary();
        if (null === $git || !$this->repository) {
            $none = '';
            $reason = 'git is not available';
            $onDone(Outcome::Failed, $none, $reason);

            return;
        }
        /** @var list<string> $command */
        // --no-optional-locks: the porcelain readers (status/diff) must never
        // take the opportunistic index lock — under load it collides with a
        // concurrent add/commit's MANDATORY index.lock and fails the mutation.
        $command = [$git, ...$this->untrusted, '--no-optional-locks', '-C', $this->rootDir, ...$args];
        $started = $this->lane->start(
            new AsyncProcess($command, $this->rootDir, captureStderr: true, env: $this->environment()),
            static function (int $code, string $out, AsyncProcess $process) use ($onDone): void {
                $onDone(Outcome::of(0 === $code), $out, $process->errorOutput());
            },
        );
        if (!$started) {
            // @codeCoverageIgnoreStart
            $none = '';
            $reason = 'could not start git';
            $onDone(Outcome::Failed, $none, $reason);

            // @codeCoverageIgnoreEnd
        }
    }
}
