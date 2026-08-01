<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\Plugin\Api\PluginApi;

/**
 * The toggleable whole-file blame mode: each line gets a dim trailing
 * `sha author date` annotation from one batch `git blame --line-porcelain`.
 *
 * Re-applies on buffer switches and saves while enabled, because the editor
 * drops its annotations on every document show — which is exactly why
 * {@see PluginApi::setLineAnnotations()} takes no path.
 *
 * Like the gutter marks this is computed against the file ON DISK, so it
 * drifts against unsaved edits until the next save re-syncs it.
 */
final class BlameAnnotations
{
    private bool $enabled;

    public function __construct(
        private readonly BlameClient $blame,
        private readonly PluginApi $api,
        bool $showByDefault = false,
    ) {
        // The core "Show blame annotations by default" setting seeds this; the
        // first buffer switch then fetches (see refreshIfEnabled).
        $this->enabled = $showByDefault;
    }

    public function toggle(): void
    {
        if ($this->enabled) {
            $this->enabled = false;
            $this->api->setLineAnnotations([]);
            $this->api->flash($this->api->trans('Blame annotations hidden'));

            return;
        }
        if (!$this->blame->isAvailable() || null === $this->api->activeFile()) {
            $this->api->flash($this->api->trans('Git not available'));

            return;
        }
        $this->enabled = true;
        $this->api->flash($this->api->trans('Blame annotations shown'));
        $this->refresh();
    }

    /**
     * The onBufferSwitched / onFileSaved hooks both land here.
     *
     * The guard is an OPTIMISATION, not the gate: with blame off, refresh()'s
     * own callback drops the answer anyway, so deleting this changes nothing a
     * test can observe — it only stops a `git blame` subprocess being spawned on
     * every buffer switch. Mutation-checked and recorded rather than covered,
     * because covering it would mean exposing the process count.
     */
    public function refreshIfEnabled(): void
    {
        if ($this->enabled) {
            $this->refresh();
        }
    }

    private function refresh(): void
    {
        $path = $this->api->activeFile();
        if (null === $path) {
            return;
        }
        $this->blame->file($path, function (array $lines) use ($path): void {
            // Stale or superseded: toggled off, or the buffer changed in flight.
            if (!$this->enabled || $path !== $this->api->activeFile()) {
                return;
            }
            $annotations = [];
            foreach ($lines as $no => $entry) {
                $annotations[$no] = trim(\sprintf('%s %s %s', $entry['sha'], $entry['author'], $entry['date']));
            }
            $this->api->setLineAnnotations($annotations);
        });
    }
}
