<?php

declare(strict_types=1);

namespace Porta\GitPlugin;

use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\FiberWait;

/**
 * The working tree as tools: what changed, and how — the two questions an
 * agent asks before and after it edits. Read-only on purpose: a stage, a
 * commit or a checkout is the user's, from the git view, where they see it.
 */
final readonly class GitTools
{
    public function __construct(
        private PluginApi $api,
        private GitProcessRunner $git,
    ) {}

    public function register(): void
    {
        $this->api->registerTool(
            'status',
            'The branch and every changed, staged or untracked file, as `git status --short --branch` prints it.',
            ['type' => 'object', 'properties' => []],
            fn (array $arguments): string => $this->run(['status', '--short', '--branch', '--untracked-files=all']),
        );
        $this->api->registerTool(
            'diff',
            'The working tree\'s unstaged changes as a unified diff — or, with staged, what is staged; with a path, that file only.',
            [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Project-relative'],
                    'staged' => ['type' => 'boolean'],
                ],
            ],
            $this->diff(...),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function diff(array $arguments): string
    {
        $args = ['diff', '--no-color', '--no-ext-diff'];
        if (true === ($arguments['staged'] ?? false)) {
            $args[] = '--cached';
        }
        $path = \is_string($arguments['path'] ?? null) ? $arguments['path'] : '';
        if ('' !== $path) {
            // After `--`, so a path is never read as an option; git itself
            // refuses one outside the repository.
            $args[] = '--';
            $args[] = $path;
        }
        $diff = $this->run($args);

        return '' === $diff ? 'No changes' : $diff;
    }

    /** @param list<string> $args */
    private function run(array $args): string
    {
        /** @var array{0: int, 1: string} $answer */
        $answer = FiberWait::for(fn (\Closure $done) => $this->git->runStatus($args, static fn (int $code, string $out) => $done([$code, $out])));
        [$code, $out] = $answer;
        if (0 !== $code) {
            throw new \RuntimeException(\sprintf('git %s failed (exit %d)', $args[0], $code));
        }

        return $out;
    }
}
