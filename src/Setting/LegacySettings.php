<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Setting;

use Porta\Support\JsonFile;

/**
 * One-time adoption of the settings this plugin's keys replaced.
 *
 * Git's eight settings were core's, keyed `gitShowBranch`, `gitPath` and so on
 * inside the `settings` block of `.porta.json` (where the pre-move
 * PortaJsonWriter::persistSetting always wrote), with a "Version control >
 * Git" page of their own. They are `plugins.git.showBranch` and friends now,
 * on this plugin's own page, because that is what every other plugin's
 * settings do.
 *
 * That rename is the one irreversible user-visible part of the move, and the
 * failure without this is silent: a user who turned the branch indicator off
 * two years ago would find it back on, with their old key sitting inertly in
 * the file. So the old keys are read once, for whichever of the new ones has
 * never been written.
 *
 * Reads `.porta.json` directly rather than going through `PluginApi`: the
 * question is "was this key ever written?", and `setting()` cannot answer it —
 * an absent key and a key explicitly set to the default are the same value.
 *
 * Adoption is per-key, not all-or-nothing: a user who changed one setting and
 * left the rest keeps that one and gets current defaults for the others, which
 * is what they had.
 */
final readonly class LegacySettings
{
    /** new key => the core key it replaced. */
    private const array RENAMED = [
        'showBranch' => 'gitShowBranch',
        'gutterMarks' => 'gitGutterMarks',
        'blameDefault' => 'gitBlameDefault',
        'signCommits' => 'gitSignCommits',
        'noVerify' => 'gitNoVerify',
        'signoff' => 'gitSignoff',
        'refreshInterval' => 'gitRefreshInterval',
        'path' => 'gitPath',
    ];

    /** @var array<array-key, mixed> the decoded `.porta.json`, or [] when there is none */
    private array $file;

    public function __construct(string $rootDir)
    {
        $this->file = JsonFile::read($rootDir.'/.porta.json') ?? [];
    }

    /**
     * The values to seed {@see GitSettings} with: this plugin's own where they
     * exist, the pre-move key's where they do not.
     *
     * @param \Closure(string): (bool|int|string) $current this plugin's resolved value for a key
     *
     * @return array<string, bool|int|string>
     */
    public function seed(\Closure $current): array
    {
        $seed = [];
        foreach (self::RENAMED as $key => $legacy) {
            $adopted = $this->adopt($key, $legacy);
            $seed[$key] = $adopted ?? $current($key);
        }

        return $seed;
    }

    /**
     * The keys whose value came from a pre-move one, so the caller can write
     * them through and stop the file answering two questions at once.
     *
     * @return array<string, bool|int|string>
     */
    public function adoptions(): array
    {
        $adopted = [];
        foreach (self::RENAMED as $key => $legacy) {
            $value = $this->adopt($key, $legacy);
            if (null !== $value) {
                $adopted[$key] = $value;
            }
        }

        return $adopted;
    }

    /** The pre-move value for $key, or null when there is nothing to adopt. */
    private function adopt(string $key, string $legacy): bool|int|string|null
    {
        /** @var mixed $plugins */
        $plugins = $this->file['plugins'] ?? null;
        /** @var mixed $mine */
        $mine = \is_array($plugins) ? $plugins['git'] ?? null : null;
        if (\is_array($mine) && array_key_exists($key, $mine)) {
            return null; // already answered under the new key; nothing to adopt
        }
        // The pre-move writer put every core setting inside the `settings`
        // block — the top level never held these keys, so only this location
        // can hold a value worth adopting.
        /** @var mixed $settings */
        $settings = $this->file['settings'] ?? null;
        /** @var mixed $value */
        $value = \is_array($settings) ? $settings[$legacy] ?? null : null;

        return \is_bool($value) || \is_int($value) || \is_string($value) ? $value : null;
    }
}
