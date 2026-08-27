<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\Plugin\Api\PluginApi;

/**
 * The gutter stripe: which lines of a file differ from HEAD.
 *
 * Core used to compute this. It publishes through
 * {@see PluginApi::setChangeMarks()} now, which is path-keyed — so an answer
 * that arrives after the user switched tabs still lands on the file it was
 * computed for, and core decides whether that file is on screen.
 *
 * The `gutterMarks` setting gates BOTH halves live: core hides the gutter the
 * instant it is turned off, and the guard below — read per refresh, not baked
 * at construction — stops the diff subprocess spawning with it. Baking it left
 * a `git diff` running per buffer switch for a feature the user had just
 * switched off, until the next launch.
 */
final class ChangeMarks
{
    /**
     * Per-path in-flight coalescing: `true` means a refresh arrived while a
     * diff for that path was already running, so ONE rerun follows the answer.
     * Holding Ctrl+Tab through the same buffers used to spawn one `git diff`
     * per revisit; a skip alone would be wrong — a save landing mid-diff must
     * still get post-save marks, which the rerun provides.
     *
     * @var array<string, bool>
     */
    private array $inFlight = [];

    /**
     * @param bool|(\Closure(): bool) $enabled the `gutterMarks` setting; a CLOSURE is read per refresh, so
     *                                         turning it off stops the diffs without a restart
     */
    public function __construct(
        private readonly GitInfo $git,
        private readonly PluginApi $api,
        private readonly bool|\Closure $enabled = true,
    ) {}

    /** Whether the gutter stripe is wanted right now. */
    private function enabled(): bool
    {
        return $this->enabled instanceof \Closure ? ($this->enabled)() : $this->enabled;
    }

    /** The active file changed, was saved, or was reloaded from disk. */
    public function refresh(?string $path = null): void
    {
        $path ??= $this->api->activeFile();
        if (!$this->enabled() || null === $path || !$this->git->isAvailable()) {
            return;
        }
        if (\array_key_exists($path, $this->inFlight)) {
            $this->inFlight[$path] = true;

            return;
        }
        $this->inFlight[$path] = false;
        $this->git->diff($path, function (array $marks) use ($path): void {
            $rerun = $this->inFlight[$path] ?? false;
            unset($this->inFlight[$path]);
            $this->api->setChangeMarks($path, $marks);
            if ($rerun) {
                $this->refresh($path);
            }
        });
    }
}
