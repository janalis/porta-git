<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Process;

use Porta\Support\AsyncProcess;

/**
 * The number of the pull request open for the current branch, asked of the
 * GitHub CLI — GitHub Desktop's `#446` beside the branch name.
 *
 * Optional by construction: no `gh`, no login, no remote on GitHub, no pull
 * request, a network that does not answer — each is simply no number, and
 * nothing waits for one. Asked in the background, on the plugin's general lane,
 * so shutdown and the E2e barrier reach it like any git process.
 */
final readonly class PullRequestLookup
{
    /** Long enough for a slow API round trip; a hung one must not stay tracked. */
    private const float TIMEOUT = 15.0;

    /**
     * @param \Closure(): ?string $gh the `gh` binary, found per call so installing it needs no restart
     */
    public function __construct(
        private string $rootDir,
        private \Closure $gh,
        private ProcessLane $lane,
    ) {}

    /** @param \Closure(?int): void $onDone the open pull request's number, or null */
    public function number(\Closure $onDone): void
    {
        $gh = ($this->gh)();
        if (null === $gh) {
            $onDone(null);

            return;
        }
        $process = new AsyncProcess(
            [$gh, 'pr', 'view', '--json', 'number', '--jq', '.number'],
            $this->rootDir,
            // Never a prompt: no terminal is there to answer it.
            env: [...\getenv(), 'GH_PROMPT_DISABLED' => '1', 'GH_NO_UPDATE_NOTIFIER' => '1'],
            timeout: self::TIMEOUT,
        );
        $this->lane->start($process, static function (int $code, string $out) use ($onDone): void {
            $number = \trim($out);
            $onDone(0 === $code && \ctype_digit($number) ? (int) $number : null);
        });
    }
}
