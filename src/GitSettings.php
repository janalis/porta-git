<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

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
 * The git executable path is NOT here: it is consumed at construction by
 * {@see GitInfo}/{@see GitRemote} and is restart-required.
 */
final class GitSettings
{
    private bool $showBranch;
    private bool $gutterMarks;
    private bool $blameDefault;
    private bool $signCommits;
    private bool $noVerify;
    private bool $signoff;
    private int $refreshInterval;

    /** @param array<string, int|bool|string> $settings this plugin's resolved settings (defaults merged) */
    public function __construct(array $settings)
    {
        $this->showBranch = (bool) ($settings['showBranch'] ?? true);
        $this->gutterMarks = (bool) ($settings['gutterMarks'] ?? true);
        $this->blameDefault = (bool) ($settings['blameDefault'] ?? false);
        $this->signCommits = (bool) ($settings['signCommits'] ?? false);
        $this->noVerify = (bool) ($settings['noVerify'] ?? false);
        $this->signoff = (bool) ($settings['signoff'] ?? false);
        $this->refreshInterval = max(1, (int) ($settings['refreshInterval'] ?? 2));
    }

    public function showBranch(): bool
    {
        return $this->showBranch;
    }

    public function setShowBranch(bool $on): void
    {
        $this->showBranch = $on;
    }

    public function gutterMarks(): bool
    {
        return $this->gutterMarks;
    }

    public function setGutterMarks(bool $on): void
    {
        $this->gutterMarks = $on;
    }

    public function blameDefault(): bool
    {
        return $this->blameDefault;
    }

    public function setBlameDefault(bool $on): void
    {
        $this->blameDefault = $on;
    }

    public function signCommits(): bool
    {
        return $this->signCommits;
    }

    public function setSignCommits(bool $on): void
    {
        $this->signCommits = $on;
    }

    public function noVerify(): bool
    {
        return $this->noVerify;
    }

    public function setNoVerify(bool $on): void
    {
        $this->noVerify = $on;
    }

    public function signoff(): bool
    {
        return $this->signoff;
    }

    public function setSignoff(bool $on): void
    {
        $this->signoff = $on;
    }

    public function refreshInterval(): int
    {
        return $this->refreshInterval;
    }

    public function setRefreshInterval(int $seconds): void
    {
        $this->refreshInterval = max(1, $seconds);
    }
}
