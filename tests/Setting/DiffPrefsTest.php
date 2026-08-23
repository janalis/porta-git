<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Setting;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Setting\DiffPrefs;
use Porta\Layout\DiffLayout;

final class DiffPrefsTest extends TestCase
{
    /** @return array<string, bool|int|string> */
    private function store(): array
    {
        return ['diffSplit' => false, 'diffHideWhitespace' => false];
    }

    public function testReadMapsATrueDiffSplitSettingToTheSplitLayout(): void
    {
        $store = $this->store();
        $store['diffSplit'] = true;
        $prefs = new DiffPrefs(static fn (string $key): bool|int|string => $store[$key], static function (): void {});

        $this->assertSame(DiffLayout::Split, $prefs->read()['layout']);
    }

    public function testReadMapsAFalseDiffSplitSettingToTheUnifiedLayout(): void
    {
        $store = $this->store();
        $prefs = new DiffPrefs(static fn (string $key): bool|int|string => $store[$key], static function (): void {});

        $this->assertSame(DiffLayout::Unified, $prefs->read()['layout']);
    }

    public function testReadCoercesAnUnderlyingNonBooleanValue(): void
    {
        // Settings are stored loosely typed (bool|int|string) — a raw 1/0 or
        // "" must still coerce, not just a real bool.
        $store = ['diffSplit' => 1, 'diffHideWhitespace' => 0];
        $prefs = new DiffPrefs(static fn (string $key): bool|int|string => $store[$key], static function (): void {});

        $read = $prefs->read();
        $this->assertSame(DiffLayout::Split, $read['layout']);
        $this->assertFalse($read['hideWhitespace']);
    }

    public function testWritePersistsSplitAsTrueAndUnifiedAsFalse(): void
    {
        $written = [];
        $prefs = new DiffPrefs(static fn (string $key): bool|int|string => false, static function (string $key, bool $value) use (&$written): void {
            $written[$key] = $value;
        });

        $prefs->write(DiffLayout::Split, hideWhitespace: true);

        $this->assertSame(['diffSplit' => true, 'diffHideWhitespace' => true], $written);

        $prefs->write(DiffLayout::Unified, hideWhitespace: false);

        $this->assertSame(['diffSplit' => false, 'diffHideWhitespace' => false], $written);
    }
}
