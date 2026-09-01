<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\Plugin\Api\PluginApi;

/**
 * The file tree's version-control marks: which files in the whole working tree
 * differ from HEAD, published through {@see PluginApi::setFileStatuses()}.
 *
 * The whole-tree counterpart of {@see ChangeMarks}, and deliberately on a
 * DIFFERENT cadence. That one answers "which lines of the file you are looking
 * at changed" and refreshes per buffer; this one runs `git status` over the
 * repository, so it refreshes only when the working tree can actually have
 * moved — a save, a commit, a stage or discard, a pull. Never per buffer
 * switch: switching tabs changes nothing on disk, and paying a `git status` for
 * it is exactly the mistake the in-flight coalescing below exists to remember.
 *
 * That coalescing mirrors ChangeMarks' for the same reason: a save landing while
 * a status is already running must still produce post-save marks, so one rerun
 * follows the answer rather than the request being dropped.
 */
final class WorkingTreeStatus
{
    /** True once a refresh arrives while one is already running: exactly one rerun follows. */
    private bool $inFlight = false;

    private bool $rerun = false;

    public function __construct(
        private readonly GitInfo $git,
        private readonly PluginApi $api,
        private readonly string $root,
    ) {}

    public function refresh(): void
    {
        if (!$this->git->isAvailable()) {
            return;
        }
        if ($this->inFlight) {
            $this->rerun = true;

            return;
        }
        $this->inFlight = true;
        $this->git->status(function (array $entries): void {
            $this->inFlight = false;
            $this->api->setFileStatuses($this->statuses($entries));
            if ($this->rerun) {
                $this->rerun = false;
                $this->refresh();
            }
        });
    }

    /**
     * `git status` gives repo-relative paths and two status letters; the tree
     * keys absolute ones and core's kinds.
     *
     * A DELETED file is skipped rather than mapped: the tree is built from what
     * is on disk, so a file that is gone has no row to mark, and publishing a
     * status for a path with no node would be data no one can see.
     *
     * @param list<ChangeEntry> $entries
     *
     * @return array<string, string> absolute path => 'added'|'modified'
     */
    private function statuses(array $entries): array
    {
        $statuses = [];
        foreach ($entries as $entry) {
            $status = $entry->displayStatus();
            if ('D' === $status) {
                continue;
            }
            $statuses[$this->root.\DIRECTORY_SEPARATOR.$entry->path] = 'A' === $status ? 'added' : 'modified';
        }

        return $statuses;
    }
}
