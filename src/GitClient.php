<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\Support\Outcome;

/**
 * The git the plugin runs, over its own {@see GitProcessRunner}.
 *
 * A plugin class cannot be a container service, so core's shared `GitInfo` is
 * not reachable — and a second process lane is the right answer anyway, for
 * the reason {@see BlameClient} gives: these are per-file reads that have no
 * business queueing behind the git view's status and diff traffic.
 *
 * It exposes what the plugin needs and nothing else. Core's `GitInfo` has
 * thirty-odd porcelain verbs because the git view needs them; a producer of
 * gutter marks needs one.
 */
final class GitClient
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
     * Stage one file.
     *
     * @param callable(bool): void $onDone
     */
    public function stage(string $absPath, callable $onDone): void
    {
        $this->runner->runStatus(['add', '--', $absPath], static fn (int $code) => $onDone(0 === $code));
    }

    /**
     * Undo the last commit, keeping its changes staged (`reset --soft`).
     * Refused when HEAD has no parent — there is nothing to undo onto.
     *
     * @param callable(bool): void $onDone
     */
    public function undoLastCommit(callable $onDone): void
    {
        $this->runner->runStatus(['rev-parse', '--verify', 'HEAD~1'], function (int $code) use ($onDone): void {
            if (0 !== $code) {
                $onDone(false);

                return;
            }
            $this->runner->runStatus(['reset', '--soft', 'HEAD~1'], static fn (int $c) => $onDone(0 === $c));
        });
    }

    /**
     * Stash the working tree. Git's own stdout line is the message worth
     * showing — it covers the nothing-to-stash case in git's words.
     *
     * @param callable(bool, string): void $onDone ok, first line of stdout
     */
    public function stashPush(callable $onDone): void
    {
        $this->runner->runStatus(['stash', 'push'], static function (int $code, string $out) use ($onDone): void {
            // @mago-expect lint:no-literal-password -- strtok's token: is a newline delimiter, not a credential
            $firstLine = strtok(trim($out), token: "\n");
            $onDone(0 === $code, \is_string($firstLine) ? $firstLine : '');
        });
    }

    /**
     * Pop the newest stash. A conflicting pop fails with git's own stderr
     * line and leaves the conflict markers in the working tree.
     *
     * @param callable(bool, string): void $onDone ok, first line of stderr
     */
    public function stashPop(callable $onDone): void
    {
        $this->runner->runWithErrors(['stash', 'pop'], static fn (Outcome $ok, string $error) => $onDone($ok->isOk(), $error));
    }

    /**
     * Which lines of a file differ from HEAD.
     *
     * `--unified=0` is what makes the answer per-line rather than per-hunk;
     * {@see HunkParser} turns it into `line => kind`, which is the shape
     * `PluginApi::setChangeMarks()` takes.
     *
     * @param callable(array<int, string>): void $onDone 0-based line => 'added'|'modified'|'deleted'
     */
    public function diff(string $absPath, callable $onDone): void
    {
        $this->runner->run(['diff', '--no-color', '--unified=0', 'HEAD', '--', $absPath], static function (string $out) use ($onDone): void {
            $onDone(HunkParser::parse($out));
        });
    }
}
