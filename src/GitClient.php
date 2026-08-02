<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\Git\GitProcessRunner;
use Porta\Git\HunkParser;

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
