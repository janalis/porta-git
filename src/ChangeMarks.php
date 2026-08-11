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
        $this->git->diff($path, function (array $marks) use ($path): void {
            $this->api->setChangeMarks($path, $marks);
        });
    }
}
