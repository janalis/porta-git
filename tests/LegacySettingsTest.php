<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\GitSettings;
use Porta\GitPlugin\LegacySettings;
use Porta\Tests\Support\TempProject;

/**
 * The one irreversible user-visible part of git becoming a plugin.
 *
 * Eight settings were core's, keyed `gitShowBranch`, `gitPath` and so on at the
 * top level of `.porta.json`, with a "Version control > Git" page. They are
 * `plugins.git.*` now, on this plugin's own page. A user who turned the branch
 * indicator off two years ago must not find it back on — and the failure is
 * silent: the old key just sits in the file, read by nobody.
 *
 * Adoption is per-key, so a user who changed one setting and left the rest
 * keeps that one and gets current defaults for the others, which is what they
 * had.
 */
final class LegacySettingsTest extends TestCase
{
    /** @param array<string, mixed> $porta */
    private function legacy(array $porta): LegacySettings
    {
        $project = new TempProject(['.porta.json' => json_encode($porta, JSON_THROW_ON_ERROR)]);
        $legacy = new LegacySettings($project->dir);
        // The decode happens in the constructor, so the TempProject may go.
        unset($project);

        return $legacy;
    }

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

    public function testAPreMoveValueIsAdoptedForAKeyNobodyHasWritten(): void
    {
        $legacy = $this->legacy(['settings' => [], 'gitShowBranch' => false, 'gitPath' => '/opt/git']);

        $seed = $legacy->seed($this->defaults());

        $this->assertFalse($seed['showBranch'], 'the old value carried over');
        $this->assertSame('/opt/git', $seed['path']);
        $this->assertTrue($seed['gutterMarks'], 'and an untouched key keeps its current default');
        $this->assertSame(['showBranch' => false, 'path' => '/opt/git'], $legacy->adoptions(), 'only what was actually adopted is written through');
    }

    public function testTheNewKeyWinsWhereBothExist(): void
    {
        // A file mid-migration: the old key from before the move, the new one
        // from a change made after it. Adopting the old one here would undo
        // whatever the user did most recently.
        $legacy = $this->legacy([
            'gitShowBranch' => false,
            'plugins' => ['git' => ['showBranch' => true]],
        ]);

        $this->assertTrue($legacy->seed($this->defaults())['showBranch']);
        $this->assertSame([], $legacy->adoptions(), 'nothing to write through');
    }

    public function testAKeyExplicitlySetToTheDefaultIsStillAdopted(): void
    {
        // The reason this reads the FILE rather than asking PluginApi: an
        // absent key and a key set to its default are the same VALUE, and only
        // the file distinguishes them. Adopting here is a no-op in effect, but
        // it writes the key through so the settings page shows a user's
        // deliberate choice rather than an accident of the default.
        $legacy = $this->legacy(['gitGutterMarks' => true]);

        $this->assertSame(['gutterMarks' => true], $legacy->adoptions());
    }

    public function testNoFileAndNoOldKeysAdoptNothing(): void
    {
        // A fresh project, and every project created after the move.
        $legacy = $this->legacy([]);

        $this->assertSame([], $legacy->adoptions());
        $this->assertSame(
            ['showBranch' => true, 'gutterMarks' => true, 'blameDefault' => false, 'signCommits' => false, 'noVerify' => false, 'signoff' => false, 'refreshInterval' => 2, 'path' => ''],
            $legacy->seed($this->defaults()),
        );
    }

    public function testAMalformedOldValueIsIgnoredRatherThanCoerced(): void
    {
        // A hand-edited file can hold anything. An array where a bool belongs
        // must fall through to the current value, not become `true`.
        $legacy = $this->legacy(['gitShowBranch' => ['yes'], 'gitRefreshInterval' => null]);

        $this->assertSame([], $legacy->adoptions());
        $this->assertTrue($legacy->seed($this->defaults())['showBranch']);
    }

    public function testTheSeedIsExactlyWhatGitSettingsReads(): void
    {
        // The two halves have to agree on the key names, and nothing else
        // checks that: `seed()` returning `gitShowBranch` would silently give
        // every reader the default forever.
        $settings = new GitSettings($this->legacy(['gitShowBranch' => false, 'gitRefreshInterval' => 9])->seed($this->defaults()));

        $this->assertFalse($settings->showBranch());
        $this->assertSame(9, $settings->refreshInterval());
    }
}
