<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Setting;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Setting\GitSettings;
use Porta\GitPlugin\Setting\LegacySettings;
use Porta\Tests\Support\TempProject;

/**
 * This plugin's half of the core-settings adoption: the RENAMED map and its
 * agreement with what {@see GitSettings} actually reads. The adoption
 * semantics themselves (per-key, file-read, new-key-wins) are pinned on the
 * shared {@see \Porta\Plugin\Api\LegacyCoreSettings}.
 */
final class LegacySettingsTest extends TestCase
{
    /** The current-value closure `PluginApi::setting()` supplies, spelled as the registered defaults. */
    private function defaults(): \Closure
    {
        return static fn (string $key): bool|int|string => match ($key) {
            'showBranch', 'gutterMarks' => true,
            'refreshInterval' => 2,
            'path' => '',
            default => false,
        };
    }

    public function testTheSeedIsExactlyWhatGitSettingsReads(): void
    {
        // The two halves have to agree on the key names, and nothing else
        // checks that: `seed()` returning `gitShowBranch` would silently give
        // every reader the default forever. This also pins the map itself —
        // the pre-move spellings are the upgrade contract.
        $project = new TempProject(['.porta.json' => (string) json_encode(['settings' => ['gitShowBranch' => false, 'gitRefreshInterval' => 9, 'gitPath' => '/opt/git']])]);
        $legacy = LegacySettings::forProject($project->dir);

        $settings = new GitSettings($legacy->seed($this->defaults()));

        $this->assertFalse($settings->showBranch());
        $this->assertSame(9, $settings->refreshInterval());
        $this->assertSame('/opt/git', $settings->path());
        $this->assertSame(['showBranch' => false, 'refreshInterval' => 9, 'path' => '/opt/git'], $legacy->adoptions(), 'exactly the touched keys write through');
        $this->assertCount(8, LegacySettings::RENAMED, 'all eight pre-move keys stay adoptable');
    }
}
