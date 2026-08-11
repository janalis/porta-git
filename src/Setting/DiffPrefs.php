<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Setting;

use Porta\Layout\DiffLayout;

/**
 * The diff pane's two display toggles — split/unified and hide-whitespace —
 * remembered between runs.
 *
 * These were `LayoutController::gitDiffPrefs()`/`setGitDiffPrefs()` and two
 * fields on core's `LayoutPrefs`, written into `.porta/layout.json` beside the
 * pane proportions. Neither belongs to the layout: nothing about them is a
 * size, and they were there only because the git view had no store of its own.
 * They are settings, and now they are this plugin's, under
 * `plugins.git.diffSplit` and `plugins.git.diffHideWhitespace`.
 *
 * Read on every open rather than cached, because the settings page can change
 * either one while the view is closed.
 *
 * @see \Porta\Plugin\Api\PluginApi::setting()
 */
final readonly class DiffPrefs
{
    /**
     * @param \Closure(string): (bool|int|string) $read
     * @param \Closure(string, bool): void        $write
     */
    public function __construct(
        private \Closure $read,
        private \Closure $write,
    ) {}

    /** @return array{layout: DiffLayout, hideWhitespace: bool} */
    public function read(): array
    {
        return [
            'layout' => (bool) ($this->read)('diffSplit') ? DiffLayout::Split : DiffLayout::Unified,
            'hideWhitespace' => (bool) ($this->read)('diffHideWhitespace'),
        ];
    }

    public function write(DiffLayout $layout, bool $hideWhitespace): void
    {
        ($this->write)('diffSplit', DiffLayout::Split === $layout);
        ($this->write)('diffHideWhitespace', $hideWhitespace);
    }
}
