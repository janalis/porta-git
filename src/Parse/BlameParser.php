<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Parse;

/**
 * Parses whole-file `git blame --line-porcelain` output into per-line
 * annotations. Porcelain emits a commit's metadata (author, author-time)
 * only on its FIRST block; later blocks reference the sha alone — so the
 * metadata is cached per full sha and each header resolves from the cache.
 * Uncommitted lines arrive under the zero sha with author "Not Committed
 * Yet" and are displayed verbatim.
 */
final class BlameParser
{
    /** @return array<int, array{sha: string, author: string, date: string}> 0-based line => entry */
    public static function parse(string $porcelain): array
    {
        /** @var array<string, string> $authors */
        $authors = [];
        /** @var array<string, int> $times */
        $times = [];
        /** @var array<int, string> $lines */
        $lines = [];
        $sha = '';
        foreach (\explode("\n", $porcelain) as $line) {
            $m = [];
            if (1 === \preg_match('/^([0-9a-f]{40}) \d+ (\d+)/', $line, $m)) {
                $sha = $m[1];
                $lines[(int) $m[2] - 1] = $sha; // porcelain lines are 1-based

                continue;
            }
            if (\str_starts_with($line, 'author ') && '' !== $sha) {
                $authors[$sha] = \substr($line, offset: 7);

                continue;
            }
            if (\str_starts_with($line, 'author-time ') && '' !== $sha) {
                $times[$sha] = (int) \substr($line, offset: 12);
            }
        }

        $entries = [];
        foreach ($lines as $no => $lineSha) {
            $time = $times[$lineSha] ?? 0;
            $entries[$no] = [
                'sha' => \substr($lineSha, offset: 0, length: 8),
                'author' => $authors[$lineSha] ?? '',
                // Uncommitted lines carry no author-time — no fake epoch date.
                'date' => 0 === $time ? '' : \date('Y-m-d', $time),
            ];
        }

        return $entries;
    }

    /**
     * @return array{sha: string, author: string, summary: string}|null
     */
    public static function parseLine(string $out): ?array
    {
        $sha = '';
        $author = '';
        $summary = '';
        foreach (\explode("\n", $out) as $line) {
            $m = [];
            if (1 === \preg_match('/^([0-9a-f]{40}) /', $line, $m)) {
                $sha = \substr($m[1], offset: 0, length: 8);

                continue;
            }
            if (\str_starts_with($line, 'author ')) {
                $author = \substr($line, offset: 7);

                continue;
            }
            if (\str_starts_with($line, 'summary ')) {
                $summary = \substr($line, offset: 8);
            }
        }

        return '' === $sha ? null : ['sha' => $sha, 'author' => $author, 'summary' => $summary];
    }
}
