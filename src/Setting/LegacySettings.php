<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Setting;

use Porta\Plugin\Api\LegacyCoreSettings;

/**
 * This plugin's half of the one-time core-settings adoption: the key map.
 *
 * Git's eight settings were core's, keyed `gitShowBranch`, `gitPath` and so
 * on inside the `settings` block of `.porta.json`, with a "Version control >
 * Git" page of their own. They are `plugins.git.showBranch` and friends now,
 * on this plugin's own page. The adoption SEMANTICS — per-key, file-read,
 * new-key-wins — live in {@see LegacyCoreSettings}, shared with the viewer
 * plugins whose settings made the same move.
 */
final readonly class LegacySettings
{
    /** new key => the core key it replaced. */
    public const array RENAMED = [
        'showBranch' => 'gitShowBranch',
        'gutterMarks' => 'gitGutterMarks',
        'blameDefault' => 'gitBlameDefault',
        'signCommits' => 'gitSignCommits',
        'noVerify' => 'gitNoVerify',
        'signoff' => 'gitSignoff',
        'refreshInterval' => 'gitRefreshInterval',
        'path' => 'gitPath',
    ];

    public static function forProject(string $rootDir): LegacyCoreSettings
    {
        return new LegacyCoreSettings($rootDir, 'git', self::RENAMED);
    }
}
