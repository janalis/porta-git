<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Support;

use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StatusReads;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;

/**
 * Keys into the editor's list and prompt, and the loop pumped until what
 * the git verb does lands — the rig of every test that drives a git command
 * through its pickers against a real repository.
 *
 * The flash is the signal a simple action ends on: it reaches the status bar
 * the test owns, which is why both live here.
 */
trait DrivesPickers
{
    use StatusReads;
    use PumpsLoop;

    private CodeEditorWidget $editor;
    private StatusBarWidget $statusBar;

    /** Type printable characters into the open prompt, or the list's filter. */
    private function type(string $text): void
    {
        foreach (\mb_str_split($text) as $ch) {
            $this->key($ch);
        }
    }

    /** Feed one key event to the editor (routed to the open prompt/overlay). */
    private function key(string $data): void
    {
        $this->editor->handleInput($data);
    }

    /**
     * Pump the event loop until $until holds. With no predicate it waits for a
     * flash to land — the signal the simple actions end on; the overlay flows
     * wait for the list to open. No fixed drain follows: a test that needs a
     * refresher's effect awaits that effect.
     */
    private function pumpUntil(?callable $until = null, float $timeout = 10.0, string $what = 'a flash to land'): void
    {
        $this->await($until ?? fn (): bool => '' !== \trim($this->statusRow($this->statusBar)), $timeout, $what);
    }

    /**
     * Pump for a fixed span with nothing to await — the deliberate cousin of
     * {@see pumpUntil()}, for the tests that assert a NON-event ("no commit was
     * made", "it did not flash unavailable"). Those cannot use pumpUntil(): its
     * predicate waits for a flash that by definition never lands, so they were
     * relying on the timeout falling through silently. Prefer pumpUntil() whenever
     * a real condition exists.
     */
    private function drain(float $seconds = 0.2): void
    {
        $this->pump($seconds);
    }

    /** Waits for the editor's list overlay. */
    private function overlayOpens(): void
    {
        $this->pumpUntil(fn (): bool => EditorState::pickerOpen($this->editor), what: 'the list');
    }

    /** Waits for the editor's one-line prompt. */
    private function promptOpens(): void
    {
        $this->pumpUntil(fn (): bool => EditorState::promptOpen($this->editor), what: 'the prompt');
    }
}
