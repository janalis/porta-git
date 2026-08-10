<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * The centred pick-one list and the one-line prompt, plus which file the editor
 * is showing — the three things this code asked a `CodeEditorWidget` for.
 *
 * It asked a widget because it used to be core and could have one injected. A
 * plugin cannot: the container is compiled before any plugin PHP runs. All
 * three exist on `PluginApi` already ({@see \Porta\Plugin\Api\PluginApi::pick()},
 * `prompt()`, `activeFile()`), and this is those three behind the names the
 * call sites already use, so the pickers and prompts read unchanged.
 *
 * The overlays themselves are no longer the editor's either — Phase A floated
 * the modal layer above `main`, which is why a branch picker opened from the
 * git view now paints instead of being clipped away with the editor.
 */
final readonly class Modals
{
    /**
     * @param \Closure(string, list<array{label: string, hint: string, value: string}>, \Closure(string): void): void $pick
     * @param \Closure(string, string, \Closure(string): void): void       $prompt
     * @param \Closure(): (string|null)                                    $activeFile
     */
    public function __construct(
        private \Closure $pick,
        private \Closure $prompt,
        private \Closure $activeFile,
    ) {}

    /**
     * @param list<array{label: string, hint: string, value: string}> $items
     * @param \Closure(string): void                                  $onPick
     */
    public function openListOverlay(string $title, array $items, \Closure $onPick): void
    {
        ($this->pick)($title, $items, $onPick);
    }

    /** @param \Closure(string): void $onSubmit */
    public function openPrompt(string $label, string $initial, \Closure $onSubmit): void
    {
        ($this->prompt)($label, $initial, $onSubmit);
    }

    /** The file the editor is showing, or null when it is showing none. */
    public function getFilePath(): ?string
    {
        return ($this->activeFile)();
    }
}
