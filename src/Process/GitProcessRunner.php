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
 * Its one piece of mutable state is a cache — an untrusted repository's
 * overrides, read once. The in-flight set lives in the
 * {@see ProcessLane} it is given, so that a set of processes can be cancelled
 * and counted as a group by something that does not know what is in it.
 * {@see GitInfo}'s thirty-odd porcelain verbs are, once this is out of the way,
 * stateless wrappers that build an argv and parse an answer, which is the
 * reason for the split.
 */
final class GitProcessRunner
{
    private readonly bool $repository;

    private readonly bool $trusted;

    /**
     * The `-c` overrides an untrusted repository's git runs under, read from
     * its config on the first spawn — through the git that spawn found — and
     * again whenever what they were read from changes.
     */
    private ?UntrustedConfig $untrusted = null;

    private readonly ProcessLane $lane;

    /** The verbs that write the index, and so take `index.lock`: run one at a time ({@see inTurn()}). */
    private const array INDEX_WRITERS = ['add', 'restore', 'apply', 'commit', 'reset', 'rm', 'mv', 'checkout', 'switch', 'stash', 'merge', 'rebase', 'cherry-pick', 'revert', 'am'];

    private bool $writing = false;

    /** @var list<\Closure(\Closure(): void): void> index writers waiting their turn */
    private array $waiting = [];

    /**
     * $lane is optional and TRAILING so every direct construction — the tests,
     * and anything that only wants its own git — keeps working unchanged. What
     * passes one is `porta.php`, so that shutdown and the completion barrier
     * can reach these processes through {@see GitProcesses}.
     *
     * @param null|(\Closure(): ?string)|string $gitPath the configured `git` path, or a CLOSURE read per
     *                                                   call so a Settings edit applies without a restart
     */
    public function __construct(
        private readonly string $rootDir,
        private readonly \Closure|string|null $gitPath = null,
        ?ProcessLane $lane = null,
    ) {
        $this->lane = $lane ?? new ProcessLane();
        // file_exists, not is_dir: a linked worktree's and a submodule's .git is a
        // FILE holding `gitdir: …`. is_dir left the whole feature silently dead
        // in both — git ran nowhere and the view said "not a repository".
        $this->repository = \file_exists($rootDir.'/.git');
        // An untrusted repository's own config is not obeyed where it would run
        // something — UntrustedConfig has the list, and why.
        //
        // Nor its attributes: `.gitattributes` names filter and textconv
        // drivers (`filter.x.clean = …`), and a status re-hashing a touched
        // file runs the clean filter. Attributes read from the empty tree are
        // none at all; `.git/info/attributes` is still read, which is why the
        // drivers themselves are overridden too.
        $this->trusted = WorkspaceTrust::fromEnvironment()->isTrusted($rootDir);
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
     * The child's environment: inherited, with paths read literally — and,
     * for an untrusted repository, its attributes read from the empty tree —
     * and $extra on top. Per spawn, so a PATH changed since boot is the one git
     * sees.
     *
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function environment(array $extra = []): array
    {
        // Every path is a file's name, never a pattern: read as a pathspec,
        // `pages/[id].tsx` also matched `pages/i.tsx` and `pages/d.tsx`, and a
        // discard of one threw away the others' changes.
        $own = ['GIT_LITERAL_PATHSPECS' => '1', ...$extra];
        if (!$this->trusted) {
            $own = ['GIT_ATTR_SOURCE' => UntrustedConfig::EMPTY_TREE, ...$own];
        }

        return [...\getenv(), ...$own];
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
        $this->waiting = [];
        $this->writing = false;
        $this->lane->cancelAll();
    }

    /**
     * Run $start now, or — for a verb that writes the index while another
     * such verb runs — once that one is done. Two at once raced for
     * `index.lock`: Space, Down, Space staged one file and refused the other
     * ("git refused the staging change"). Reads never wait.
     *
     * @param list<string>              $args
     * @param \Closure(\Closure(): void): void $start called with what to run once its process has exited
     */
    private function inTurn(array $args, \Closure $start): void
    {
        if (!\in_array($args[0] ?? '', self::INDEX_WRITERS, strict: true)) {
            $start(static function (): void {});

            return;
        }
        if ($this->writing) {
            $this->waiting[] = $start;

            return;
        }
        $this->writing = true;
        $start($this->nextWriter(...));
    }

    /** The running index writer is done: start the next one waiting, if any. */
    private function nextWriter(): void
    {
        $next = \array_shift($this->waiting);
        if (null === $next) {
            $this->writing = false;

            return;
        }
        $next($this->nextWriter(...));
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
        $command = $this->command($git, $args);
        $this->inTurn($args, function (\Closure $done) use ($command, $stdin, $onDone): void {
            $started = $this->lane->start(
                new AsyncProcess($command, $this->rootDir, $stdin, env: $this->environment()),
                static function (int $code, string $out) use ($onDone, $done): void {
                    try {
                        $onDone($code, $out);
                    } finally {
                        $done();
                    }
                },
            );
            if (!$started) {
                // @codeCoverageIgnoreStart
                $empty = '';
                $onDone(1, $empty);
                $done();

                // @codeCoverageIgnoreEnd
            }
        });
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
        $this->capture($args, $onDone, []);
    }

    /**
     * Like {@see runWithErrors()}, for a verb that would stop for an editor —
     * a merge or a rebase carrying on — where nobody is at one: `true` as the
     * editor keeps the message git prepared. The environment's own editor
     * would win over `core.editor`, so it is the environment that says so.
     *
     * @param list<string>                    $args
     * @param callable(Outcome, string): void $onDone (ok, trimmed first stderr line)
     */
    public function runUnattended(array $args, callable $onDone): void
    {
        $this->capture($args, static fn (Outcome $ok, string $out, string $errors) => $onDone($ok, Text::firstLine($errors)), ['GIT_EDITOR' => 'true', 'GIT_SEQUENCE_EDITOR' => 'true']);
    }

    /**
     * @param list<string>                            $args
     * @param callable(Outcome, string, string): void $onDone
     * @param array<string, string>                   $env    on top of the child's own
     */
    private function capture(array $args, callable $onDone, array $env): void
    {
        $git = $this->binary();
        if (null === $git || !$this->repository) {
            $none = '';
            $reason = 'git is not available';
            $onDone(Outcome::Failed, $none, $reason);

            return;
        }
        $command = $this->command($git, $args);
        $this->inTurn($args, function (\Closure $done) use ($command, $env, $onDone): void {
            $started = $this->lane->start(
                new AsyncProcess($command, $this->rootDir, captureStderr: true, env: $this->environment($env)),
                static function (int $code, string $out, AsyncProcess $process) use ($onDone, $done): void {
                    try {
                        $onDone(Outcome::of(0 === $code), $out, $process->errorOutput());
                    } finally {
                        $done();
                    }
                },
            );
            if (!$started) {
                // @codeCoverageIgnoreStart
                $none = '';
                $reason = 'could not start git';
                $onDone(Outcome::Failed, $none, $reason);
                $done();

                // @codeCoverageIgnoreEnd
            }
        });
    }

    /**
     * @param list<string> $args
     *
     * @return list<string>
     */
    private function command(string $git, array $args): array
    {
        $untrusted = [];
        if (!$this->trusted) {
            $this->untrusted = true === $this->untrusted?->isCurrent($git) ? $this->untrusted : UntrustedConfig::read($git, $this->rootDir);
            $untrusted = $this->untrusted->arguments;
        }

        // --no-optional-locks: the porcelain readers (status/diff) must never
        // take the opportunistic index lock — under load it collides with a
        // concurrent add/commit's MANDATORY index.lock and fails the mutation.
        return [$git, ...$untrusted, '--no-optional-locks', '-C', $this->rootDir, ...$args];
    }
}
