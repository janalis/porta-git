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
 * The `gitGutterMarks` setting is read once, at activation, through the env
 * channel. Turning it off mid-session hides the gutter immediately — that half
 * is core's display option — but stops the diff subprocess only on the next
 * launch, which is the same "applies on restart" contract `gitPath` carries.
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

    public function __construct(
        private readonly GitInfo $git,
        private readonly PluginApi $api,
        private readonly bool $enabled = true,
    ) {}

    /** The active file changed, was saved, or was reloaded from disk. */
    public function refresh(?string $path = null): void
    {
        $path ??= $this->api->activeFile();
        if (!$this->enabled || null === $path || !$this->git->isAvailable()) {
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
