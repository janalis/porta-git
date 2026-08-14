<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Command;

use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\GitInfo;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\Outcome;

/**
 * Stash and pop, the two git verbs that need nothing but git and a flash.
 *
 * They are the first to leave core, and they are the ones that could: the
 * others in `GitCommands` either open a picker over the git view, are called
 * back from a core context menu, or open the view itself. These two run a
 * subprocess and say what happened.
 *
 * Each ends in {@see PluginApi::emit()} with {@see WorkingTreeChanged}, which
 * is how the gutter stripe — this plugin's own, and any other listener's —
 * learns the tree moved. Core used to make that announcement on their behalf.
 */
final readonly class Stash
{
    public function __construct(
        private GitInfo $git,
        private PluginApi $api,
    ) {}

    public function push(): void
    {
        if (!$this->git->isAvailable()) {
            $this->api->flash($this->api->trans('Git not available'));

            return;
        }
        $this->git->stashPush(function (Outcome $outcome, string $line): void {
            $ok = $outcome->isOk();
            // git's own line, which says "No local changes to save" better
            // than a guess would.
            $this->api->flash($ok ? $line : $this->api->trans('Stash failed'), $ok ? 3.0 : 6.0);
            if ($ok) {
                $this->api->emit(new WorkingTreeChanged());
            }
        });
    }

    public function pop(): void
    {
        if (!$this->git->isAvailable()) {
            $this->api->flash($this->api->trans('Git not available'));

            return;
        }
        // null: the newest stash. The view's picker names one; this verb is
        // the editor half's "pop what I just pushed".
        $this->git->stashPop(null, function (Outcome $outcome, string $error): void {
            $ok = $outcome->isOk();
            $message = match (true) {
                $ok => $this->api->trans('Stash popped'),
                '' === $error => $this->api->trans('Stash failed'),
                default => $error,
            };
            $this->api->flash($message, $ok ? 2.5 : 6.0);
            if ($ok) {
                // A pop rewrites files even when it conflicts, but only a clean
                // one is worth announcing — a conflicted tree is reported by
                // the message above and re-read on the next save.
                $this->api->emit(new WorkingTreeChanged());
            }
        });
    }
}
