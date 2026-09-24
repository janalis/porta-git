<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Support;

use Porta\Overlay\State\ToastSeverity;

/**
 * What `Porta\Workbench\Notifier` was to this code, spelled as something a
 * plugin can hold.
 *
 * `Notifier` is a container service, and a plugin class can never be one — the
 * container is compiled and cached before any plugin PHP runs. `PluginApi`
 * offers the same thing as `flash()`, but four classes here took a `Notifier`
 * and passing `$api` into each of them would hand every one of them the whole
 * plugin surface for the sake of one verb.
 *
 * So it is one closure behind a method per severity, built once in
 * `plugin.php` from `$api->notify(...)`. Every call site is unchanged except
 * for the type. A `$slot` makes a message replaceable in place (see
 * `PluginApi::notify()`).
 */
final readonly class Flash
{
    /** @param \Closure(string, ToastSeverity, float, ?string): void $say */
    public function __construct(
        private \Closure $say,
    ) {}

    public function flash(string $message, float $seconds = 2.5, ?string $slot = null): void
    {
        ($this->say)($message, ToastSeverity::Info, $seconds, $slot);
    }

    public function success(string $message, float $seconds = 2.5, ?string $slot = null): void
    {
        ($this->say)($message, ToastSeverity::Success, $seconds, $slot);
    }

    public function warn(string $message, float $seconds = 4.0, ?string $slot = null): void
    {
        ($this->say)($message, ToastSeverity::Warn, $seconds, $slot);
    }

    public function error(string $message, float $seconds = 5.0, ?string $slot = null): void
    {
        ($this->say)($message, ToastSeverity::Error, $seconds, $slot);
    }
}
