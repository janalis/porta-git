<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\Git\GitProcessRunner;

/**
 * Runs `git blame` for this plugin.
 *
 * It owns a {@see GitProcessRunner} of its own rather than reaching for core's
 * — a plugin class cannot be a container service, so the shared instance is
 * not reachable, and a second one is the right answer anyway: blame is a slow
 * whole-file read that has no business queueing behind the status/diff traffic
 * the view generates. `GitRemote` makes the same argument for the same reason.
 *
 * The runner answers a run that cannot happen as exit 1 with no output, so
 * "no git" and "not a repository" arrive here as an empty parse, not an error.
 */
final class BlameClient
{
    private readonly GitProcessRunner $runner;

    public function __construct(string $rootDir, ?string $gitPath = null)
    {
        $this->runner = new GitProcessRunner($rootDir, $gitPath);
    }

    public function isAvailable(): bool
    {
        return $this->runner->isAvailable();
    }

    /**
     * Blame one line (0-based here, 1-based on the wire).
     *
     * @param callable(array{sha: string, author: string, summary: string}|null): void $onDone
     */
    public function line(string $absPath, int $line, callable $onDone): void
    {
        $n = $line + 1;
        $this->runner->run(['blame', '-L', $n.','.$n, '--line-porcelain', '--', $absPath], static fn (string $out) => $onDone(BlameParser::parseLine($out)));
    }

    /**
     * Blame every line at once, for the annotation overlay.
     *
     * @param callable(array<int, array{sha: string, author: string, date: string}>): void $onDone 0-based line => entry; [] when untracked or blame fails
     */
    public function file(string $absPath, callable $onDone): void
    {
        // The exit-code check is defensive: git prints nothing parseable when it
        // fails, so the parser returns [] either way and deleting the check
        // survives mutation. Kept because "a non-zero exit produced no data" is
        // the honest reading, not because a test can tell.
        $this->runner->runStatus(['blame', '--line-porcelain', '--', $absPath], static fn (int $code, string $out) => $onDone(0 !== $code ? [] : BlameParser::parse($out)));
    }
}
