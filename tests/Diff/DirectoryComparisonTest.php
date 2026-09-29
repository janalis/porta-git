<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Diff;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Diff\DirectoryComparison;
use Porta\Tests\Support\TempProject;

/** `git difftool --dir-diff`'s two trees, the working-tree side as symlinks. */
final class DirectoryComparisonTest extends TestCase
{
    public function testItListsWhatChangedAndFollowsTheWorkingTreesSymlinks(): void
    {
        $repo = new TempProject(['same.txt' => "same\n", 'src/changed.txt' => "after\n", 'new.txt' => "new\n"]);
        $left = new TempProject(['same.txt' => "same\n", 'src/changed.txt' => "before\n", 'gone.txt' => "gone\n"]);
        $right = new TempProject([]);
        // Git links the right side to the working tree; linked as text, every
        // file would differ from its left-hand copy.
        \mkdir($right->path('src'));
        foreach (['same.txt', 'src/changed.txt', 'new.txt'] as $path) {
            \symlink($repo->path($path), $right->path($path));
        }

        $changes = DirectoryComparison::changes($left->dir, $right->dir.'/');

        $this->assertSame(
            [
                ['gone.txt',        'D'],
                ['new.txt',         'A'],
                ['src/changed.txt', 'M'],
            ],
            \array_map(static fn (ChangeEntry $entry): array => [$entry->path, $entry->displayStatus()], $changes),
            'an identical file through a symlink is no change',
        );
    }

    public function testEachSideIsTheRealFileOrNothing(): void
    {
        $repo = new TempProject(['a.txt' => "a\n"]);
        $right = new TempProject([]);
        \symlink($repo->path('a.txt'), $right->path('a.txt'));

        $this->assertSame(\realpath($repo->path('a.txt')), DirectoryComparison::side($right->dir, 'a.txt'));
        $this->assertSame('/dev/null', DirectoryComparison::side($right->dir, 'missing.txt'), 'an added or deleted file diffs against nothing');
    }

    public function testAFileNamedLikeANumberIsStillAPath(): void
    {
        // PHP keys an array by int for "1", which ChangeEntry's string path refuses.
        $left = new TempProject([]);
        $right = new TempProject([]);
        \file_put_contents($left->path('1'), data: "one\n");
        \file_put_contents($right->path('1'), data: "two\n");

        $this->assertSame('1', DirectoryComparison::changes($left->dir, $right->dir)[0]->path);
    }

    public function testALinkToAFileThatIsGoneIsNoFile(): void
    {
        // Git links the working tree; a file deleted since is a dangling link.
        $left = new TempProject(['a.txt' => "a\n"]);
        $right = new TempProject([]);
        \symlink($right->path('missing.txt'), $right->path('a.txt'));

        $this->assertSame([['a.txt', 'D']], \array_map(static fn (ChangeEntry $entry): array => [$entry->path, $entry->displayStatus()], DirectoryComparison::changes($left->dir, $right->dir)));
    }
}
