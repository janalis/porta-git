<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Diff;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Diff\DiffDocument;
use Porta\GitPlugin\Diff\PatchBuilder;
use Porta\GitPlugin\GitInfo;
use Porta\Support\Outcome;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/**
 * The recount spec, both pure (exact patch text) and as real-git round trips:
 * craft a partial patch from a selection, apply it to the index, and assert
 * `git diff --cached` contains exactly the selected change.
 */
final class PatchBuilderTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;

    // --- pure recount ----------------------------------------------------------

    public function testSingleSelectedAdditionDemotesAndDrops(): void
    {
        // Hunk: ctx, -old, +new, +extra, ctx — select only '+extra' (index 3).
        $doc = DiffDocument::parse("@@ -3,3 +3,4 @@ demo\n keep\n-old\n+new\n+extra\n two\n");
        $patch = PatchBuilder::build('f.txt', $doc->hunks, [0 => [3 => true]]);

        $this->assertSame(<<<'PATCH'
            diff --git a/f.txt b/f.txt
            --- a/f.txt
            +++ b/f.txt
            @@ -3,3 +3,4 @@ demo
             keep
             old
            +extra
             two

            PATCH, $patch, 'unselected deletion demotes to context, unselected addition drops');
    }

    public function testPureDeletionRecountsToZeroNewLines(): void
    {
        $doc = DiffDocument::parse("@@ -1,1 +0,0 @@\n-only\n");
        $patch = PatchBuilder::build('f.txt', $doc->hunks, [0 => [0 => true]]);

        $this->assertStringContainsString('@@ -1,1 +0,0 @@', (string) $patch);
    }

    public function testPureInsertionKeepsTheOldAnchor(): void
    {
        $doc = DiffDocument::parse("@@ -5,0 +6,2 @@\n+a\n+b\n");
        $patch = PatchBuilder::build('f.txt', $doc->hunks, [0 => [0 => true, 1 => true]]);

        $this->assertStringContainsString('@@ -5,0 +6,2 @@', (string) $patch);

        // Selecting only the first insertion recounts the new side to 1.
        $partial = PatchBuilder::build('f.txt', $doc->hunks, [0 => [0 => true]]);
        $this->assertStringContainsString('@@ -5,0 +6,1 @@', (string) $partial);
    }

    public function testMultiHunkDeltaShiftsLaterNewStarts(): void
    {
        $raw = "@@ -3,3 +3,4 @@\n keep\n-old\n+new\n+extra\n two\n@@ -10,2 +11,2 @@\n tail\n-x\n+y\n";
        $doc = DiffDocument::parse($raw);

        // Select everything in both hunks: the emitted patch must reproduce the input.
        $all = [0 => [0 => true, 1 => true, 2 => true, 3 => true, 4 => true], 1 => [0 => true, 1 => true, 2 => true]];
        $patch = (string) PatchBuilder::build('f.txt', $doc->hunks, $all);
        $this->assertStringContainsString("@@ -3,3 +3,4 @@\n", $patch);
        $this->assertStringContainsString("@@ -10,2 +11,2 @@\n", $patch);

        // Select only hunk 2: hunk 1 is skipped entirely, so no delta applies —
        // the new side starts at 10, NOT the original 11 (which included hunk
        // 1's +1 shift that this patch no longer performs).
        $second = (string) PatchBuilder::build('f.txt', $doc->hunks, [1 => [1 => true, 2 => true]]);
        $this->assertStringNotContainsString('@@ -3', $second);
        $this->assertStringContainsString('@@ -10,2 +10,2 @@', $second);

        // Select hunk 1's extra addition + hunk 2: hunk 2's new side shifts by +1...
        // hunk 1 emits newCount 4 vs oldCount 3 → delta +1 → hunk 2 newStart 11.
        $both = (string) PatchBuilder::build('f.txt', $doc->hunks, [0 => [3 => true], 1 => [1 => true, 2 => true]]);
        $this->assertStringContainsString('@@ -3,3 +3,4 @@', $both);
        $this->assertStringContainsString('@@ -10,2 +11,2 @@', $both);
    }

    public function testNothingSelectedYieldsNull(): void
    {
        $doc = DiffDocument::parse("@@ -1,1 +1,1 @@\n-a\n+b\n");
        $this->assertNull(PatchBuilder::build('f.txt', $doc->hunks, []));
        $this->assertNull(PatchBuilder::build('f.txt', $doc->hunks, [0 => []]), 'empty per-hunk set');
        // Selecting only a context line is not a change.
        $ctx = DiffDocument::parse("@@ -1,2 +1,2 @@\n keep\n-a\n+b\n");
        $this->assertNull(PatchBuilder::build('f.txt', $ctx->hunks, [0 => [0 => true]]));
    }

    public function testNoNewlineMarkerFollowsItsLine(): void
    {
        $doc = DiffDocument::parse("@@ -1,1 +1,1 @@\n-old\n\\ No newline at end of file\n+new\n");
        $patch = (string) PatchBuilder::build('f.txt', $doc->hunks, [0 => [0 => true, 2 => true]]);

        $this->assertStringContainsString("-old\n\\ No newline at end of file\n+new", $patch, 'the marker rides with its kept deletion');
    }

    public function testInvertHunkMirrorsSidesAndOrigins(): void
    {
        $doc = DiffDocument::parse("@@ -3,2 +4,3 @@ head\n ctx\n-gone\n+here\n+more\n");
        $inverted = PatchBuilder::invertHunk($doc->hunks[0]);

        $this->assertSame([4, 3, 3, 2], [$inverted->oldStart, $inverted->oldCount, $inverted->newStart, $inverted->newCount]);
        $this->assertSame(['head', ' ', '+', '-', '-'], [
            $inverted->heading,
            $inverted->lines[0]->origin,
            $inverted->lines[1]->origin,
            $inverted->lines[2]->origin,
            $inverted->lines[3]->origin,
        ]);
        $this->assertSame($doc->hunks[0]->lines[1]->oldNo, $inverted->lines[1]->newNo, 'line numbers swap sides');
    }

    // --- real-git round trips ---------------------------------------------------

    public function testStagingASingleLineOfAMultiLineChange(): void
    {
        [$git, $project] = $this->repo(['f.txt' => "alpha\nbeta\ngamma\n"]);
        $project->write('f.txt', "alpha CHANGED\nbeta\ngamma CHANGED\n");

        $raw = $this->awaitDiff($git, $project->path('f.txt'), DiffSide::Worktree);
        $doc = DiffDocument::parse($raw);

        // Select only the FIRST change pair (-alpha/+alpha CHANGED).
        $selected = [];
        foreach ($doc->hunks as $h => $hunk) {
            foreach ($hunk->lines as $i => $line) {
                if (!str_contains($line->text, 'alpha')) {
                    continue;
                }

                $selected[$h][$i] = true;
            }
        }
        $patch = PatchBuilder::build('f.txt', $doc->hunks, $selected);
        $this->assertNotNull($patch);
        $this->applyOk($git, $patch);

        $cached = $this->gitOut($project, 'diff --cached');
        $this->assertStringContainsString('+alpha CHANGED', $cached);
        $this->assertStringNotContainsString('gamma CHANGED', $cached, 'the unselected change stayed out of the index');
        $worktree = $this->gitOut($project, 'diff');
        $this->assertStringContainsString('+gamma CHANGED', $worktree, 'the unselected change remains in the worktree diff');
    }

    public function testStagingAPureInsertionAndAPureDeletion(): void
    {
        [$git, $project] = $this->repo(['f.txt' => "one\ntwo\nthree\nfour\n"]);
        $project->write('f.txt', "one\ninserted\ntwo\nfour\n"); // +inserted, -three

        $doc = DiffDocument::parse($this->awaitDiff($git, $project->path('f.txt'), DiffSide::Worktree));

        // Stage ONLY the deletion of 'three'.
        $selected = [];
        foreach ($doc->hunks as $h => $hunk) {
            foreach ($hunk->lines as $i => $line) {
                if (!('-' === $line->origin && 'three' === $line->text)) {
                    continue;
                }

                $selected[$h][$i] = true;
            }
        }
        $this->applyOk($git, (string) PatchBuilder::build('f.txt', $doc->hunks, $selected));

        $cached = $this->gitOut($project, 'diff --cached');
        $this->assertStringContainsString('-three', $cached);
        $this->assertStringNotContainsString('inserted', $cached);
    }

    public function testUnstagingViaInvertedCachedDiff(): void
    {
        [$git, $project] = $this->repo(['f.txt' => "alpha\nbeta\n"]);
        $project->write('f.txt', "alpha CHANGED\nbeta CHANGED\n");
        $this->gitOut($project, 'add f.txt'); // everything staged

        $doc = DiffDocument::parse($this->awaitDiff($git, $project->path('f.txt'), DiffSide::Cached));
        $inverted = array_map(PatchBuilder::invertHunk(...), $doc->hunks);

        // Unstage only the beta change: select its ± pair in the INVERTED hunks.
        $selected = [];
        foreach ($inverted as $h => $hunk) {
            foreach ($hunk->lines as $i => $line) {
                if (!str_contains($line->text, 'beta')) {
                    continue;
                }

                $selected[$h][$i] = true;
            }
        }
        $this->applyOk($git, (string) PatchBuilder::build('f.txt', $inverted, $selected));

        $cached = $this->gitOut($project, 'diff --cached');
        $this->assertStringContainsString('+alpha CHANGED', $cached, 'alpha stays staged');
        $this->assertStringNotContainsString('beta CHANGED', $cached, 'beta left the index');
        $this->assertStringContainsString('+beta CHANGED', $this->gitOut($project, 'diff'), 'beta is back in the worktree diff');
    }

    // --- harness ---------------------------------------------------------------

    /** @return array{GitInfo, TempProject} */
    private function repo(array $files): array
    {
        $this->skipWithoutGit();
        $project = new TempProject($files);
        $project->initGit();
        $project->commitAll('init');

        return [new GitInfo($project->dir), $project];
    }

    private function awaitDiff(GitInfo $git, string $abs, DiffSide $side): string
    {
        $raw = null;
        $cb = static function (string $out) use (&$raw): void {
            $raw = $out;
        };
        DiffSide::Worktree === $side ? $git->worktreeFileDiff($abs, $cb) : $git->cachedFileDiff($abs, $cb);
        $this->await(static function () use (&$raw): bool {
            return null !== $raw;
        });

        return (string) $raw;
    }

    private function applyOk(GitInfo $git, string $patch): void
    {
        $ok = null;
        $git->applyCached($patch, static function (Outcome $result) use (&$ok): void {
            $ok = $result->isOk();
        });
        $this->await(static function () use (&$ok): bool {
            return null !== $ok;
        });
        $this->assertTrue($ok, "git apply --cached accepted the patch:\n".$patch);
    }

    private function gitOut(TempProject $project, string $args): string
    {
        return $project->git(...explode(' ', $args));
    }
}

/** Which side of the index a diff is taken against. */
// @mago-expect lint:single-class-per-file -- test-local helper enum, kept beside the test that uses it
enum DiffSide
{
    case Worktree;
    case Cached;
}
