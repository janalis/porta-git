<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Setting;

/**
 * The mutable holder for this plugin's settings, shared by everything git-facing
 * (status cell, gutter, refresher, blame, commit, poll).
 *
 * Seeded once at construction so the readers see the right initial state
 * without a boot-apply pass, and mutated in place by the settings page — which
 * is why it is a holder rather than a repeated `$api->setting()` read: the
 * gutter and the branch cell have to change the same frame the user toggles
 * them, and a value re-read per render would still be right but a value cached
 * in six places would not.
 *
 * The git executable path is here too, and live like the rest: the runners
 * resolve it per spawn through a closure over this holder, so an edit reaches
 * the next git command with no restart.
 */
final class GitSettings
{
    /** The configured `git` binary, or '' to discover one. */
    public string $path;
    public bool $showBranch;
    public bool $gutterMarks;
    public bool $blameDefault;
    public bool $signCommits;
    public bool $noVerify;
    public bool $signoff;

    /** Seconds between background status polls; never below one. */
    public int $refreshInterval {
        set(int $seconds) {
            $this->refreshInterval = \max(1, $seconds);
        }
    }

    /** @param array<string, int|bool|string> $settings this plugin's resolved settings (defaults merged) */
    public function __construct(array $settings)
    {
        $this->showBranch = (bool) ($settings['showBranch'] ?? true);
        $this->gutterMarks = (bool) ($settings['gutterMarks'] ?? true);
        $this->blameDefault = (bool) ($settings['blameDefault'] ?? false);
        $this->signCommits = (bool) ($settings['signCommits'] ?? false);
        $this->noVerify = (bool) ($settings['noVerify'] ?? false);
        $this->signoff = (bool) ($settings['signoff'] ?? false);
        $this->refreshInterval = (int) ($settings['refreshInterval'] ?? 2);
        $this->path = (string) ($settings['path'] ?? '');
    }
}
