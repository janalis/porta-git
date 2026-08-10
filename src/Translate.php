<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

/**
 * `Porta\I18n\Translator`'s one verb, as something a plugin can hold.
 *
 * The {@see Flash} and {@see Modals} reasoning: a plugin class can never be a
 * container service, so the `Translator` these classes were injected with is
 * out of reach — but `PluginApi::trans()` is the same lookup against the same
 * catalogue, and the view's own half receives the real `Translator` on
 * `ViewServices` anyway.
 *
 * Named for the verb rather than the type so the two are not confused where
 * both are in scope: the widgets take a `Translator`, everything built during
 * `activate()` takes this.
 */
final readonly class Translate
{
    /** @param \Closure(string, array<string, string>): string $trans */
    public function __construct(
        private \Closure $trans,
    ) {}

    /** @param array<string, string> $params `%name%` => replacement */
    public function trans(string $id, array $params = []): string
    {
        return ($this->trans)($id, $params);
    }
}
