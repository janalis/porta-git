<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Blame;

use Porta\Plugin\Api\PluginApi;

/** "Git: Blame Current Line" — one line's commit, flashed. */
final readonly class BlameLine
{
    public function __construct(
        private BlameClient $blame,
        private PluginApi $api,
    ) {}

    public function show(): void
    {
        $path = $this->api->activeFile();
        $caret = $this->api->caretPosition();
        if (null === $path || null === $caret || !$this->blame->isAvailable()) {
            $this->api->flash($this->api->trans('Git not available'));

            return;
        }
        $this->blame->line(
            $path,
            $caret['line'],
            /** @param array{sha: string, author: string, summary: string}|null $entry */
            function (?array $entry): void {
                $this->api->flash(
                    null === $entry
                        ? $this->api->trans('No blame for this line')
                        : \sprintf('%s  %s: %s', $entry['sha'], $entry['author'], $entry['summary']),
                );
            },
        );
    }
}
