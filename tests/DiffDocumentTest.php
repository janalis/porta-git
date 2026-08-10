<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\DiffDocument;
use Porta\GitPlugin\DiffRowKind;

final class DiffDocumentTest extends TestCase
{
    private const string MODIFIED = <<<'DIFF'
        diff --git a/src/a.php b/src/a.php
        index 123..456 100644
        --- a/src/a.php
        +++ b/src/a.php
        @@ -3,4 +3,5 @@ function demo()
         context one
        -old line
        +new line
        +extra line
         context two
        @@ -20,2 +21,2 @@
         tail context
        -last old
        +last new

        DIFF;

    public function testParsesHunksWithLineNumbers(): void
    {
        $doc = DiffDocument::parse(self::MODIFIED);

        $this->assertFalse($doc->isEmpty());
        $this->assertCount(4, $doc->headerLines);
        $this->assertCount(2, $doc->hunks);

        $first = $doc->hunks[0];
        $this->assertSame([3, 4, 3, 5, 'function demo()'], [$first->oldStart, $first->oldCount, $first->newStart, $first->newCount, $first->heading]);
        $this->assertSame('@@ -3,4 +3,5 @@ function demo()', $first->header());

        [$ctx1, $del, $add1, $add2, $ctx2] = $first->lines;
        $this->assertSame([' ', 'context one', 3, 3], [$ctx1->origin, $ctx1->text, $ctx1->oldNo, $ctx1->newNo]);
        $this->assertSame(['-', 'old line', 4, null], [$del->origin, $del->text, $del->oldNo, $del->newNo]);
        $this->assertSame(['+', 'new line', null, 4], [$add1->origin, $add1->text, $add1->oldNo, $add1->newNo]);
        $this->assertSame(['+', 'extra line', null, 5], [$add2->origin, $add2->text, $add2->oldNo, $add2->newNo]);
        $this->assertSame([' ', 'context two', 5, 6], [$ctx2->origin, $ctx2->text, $ctx2->oldNo, $ctx2->newNo]);

        $second = $doc->hunks[1];
        $this->assertSame(20, $second->lines[0]->oldNo);
        $this->assertSame(21, $second->lines[0]->newNo);
    }

    public function testRowsFlattenHeadersAndBodyInOrder(): void
    {
        $rows = DiffDocument::parse(self::MODIFIED)->rows();

        $this->assertSame(['kind' => DiffRowKind::Hunk, 'hunk' => 0, 'line' => -1], $rows[0]);
        $this->assertSame(DiffRowKind::Line, $rows[1]['kind']);
        $this->assertSame(5 + 3 + 2, \count($rows), 'two hunk headers plus eight body lines');
        $this->assertSame(['kind' => DiffRowKind::Hunk, 'hunk' => 1, 'line' => -1], $rows[6]);
    }

    public function testSingleLineCountsAreImplicitOnes(): void
    {
        $doc = DiffDocument::parse("@@ -5 +6 @@\n-a\n+b\n");

        $hunk = $doc->hunks[0];
        $this->assertSame([5, 1, 6, 1], [$hunk->oldStart, $hunk->oldCount, $hunk->newStart, $hunk->newCount]);
        $this->assertSame('@@ -5,1 +6,1 @@', $hunk->header(), 'normalized explicit counts');
        $this->assertSame('', $hunk->heading);
    }

    public function testNewFileDiffNumbersOnlyTheNewSide(): void
    {
        $raw = "diff --git a/n.txt b/n.txt\nnew file mode 100644\n--- /dev/null\n+++ b/n.txt\n@@ -0,0 +1,2 @@\n+alpha\n+beta\n";
        $doc = DiffDocument::parse($raw);

        $this->assertSame([null, 1], [$doc->hunks[0]->lines[0]->oldNo, $doc->hunks[0]->lines[0]->newNo]);
        $this->assertSame([null, 2], [$doc->hunks[0]->lines[1]->oldNo, $doc->hunks[0]->lines[1]->newNo]);
    }

    public function testDeletedFileDiffNumbersOnlyTheOldSide(): void
    {
        $raw = "@@ -1,2 +0,0 @@\n-alpha\n-beta\n";
        $doc = DiffDocument::parse($raw);

        $this->assertSame([1, null], [$doc->hunks[0]->lines[0]->oldNo, $doc->hunks[0]->lines[0]->newNo]);
        $this->assertSame([2, null], [$doc->hunks[0]->lines[1]->oldNo, $doc->hunks[0]->lines[1]->newNo]);
    }

    public function testNoNewlineMarkerHasNoLineNumbers(): void
    {
        $doc = DiffDocument::parse("@@ -1 +1 @@\n-old\n\\ No newline at end of file\n+new\n");

        $marker = $doc->hunks[0]->lines[1];
        $this->assertSame('\\', $marker->origin);
        $this->assertSame(' No newline at end of file', $marker->text);
        $this->assertNull($marker->oldNo);
        $this->assertNull($marker->newNo);
    }

    public function testEmptyAndHeaderOnlyInputAreEmptyDocuments(): void
    {
        $this->assertTrue(DiffDocument::parse('')->isEmpty());
        $this->assertSame([], DiffDocument::parse('')->rows());

        $headerOnly = DiffDocument::parse("diff --git a/x b/x\nindex 1..2 100644\n");
        $this->assertTrue($headerOnly->isEmpty());
        $this->assertCount(2, $headerOnly->headerLines);
    }

    public function testRenameHeadersAreKeptAsMeta(): void
    {
        $raw = "diff --git a/old.php b/new.php\nsimilarity index 95%\nrename from old.php\nrename to new.php\n";
        $doc = DiffDocument::parse($raw);

        $this->assertTrue($doc->isEmpty());
        $this->assertContains('rename from old.php', $doc->headerLines);
    }

    public function testSplitRowsPairContextAndRuns(): void
    {
        // ctx, -a, +b, +c  → ctx pair, (a|b) pair, (blank|c) pair.
        $doc = DiffDocument::parse("@@ -1,2 +1,3 @@\n ctx\n-a\n+b\n+c\n");

        $this->assertSame(
            [
                ['kind' => DiffRowKind::Hunk, 'hunk' => 0, 'left' => -1, 'right' => -1],
                ['kind' => DiffRowKind::Pair, 'hunk' => 0, 'left' => 0, 'right' => 0],
                ['kind' => DiffRowKind::Pair, 'hunk' => 0, 'left' => 1, 'right' => 2],
                ['kind' => DiffRowKind::Pair, 'hunk' => 0, 'left' => -1, 'right' => 3],
            ],
            $doc->splitRows(),
        );
    }

    public function testSplitRowsSurplusDeletionsAndLoneAdditions(): void
    {
        // -a, -b, +c → (a|c), (b|blank); then a lone addition run +d.
        $doc = DiffDocument::parse("@@ -1,2 +1,1 @@\n-a\n-b\n+c\n@@ -9,1 +8,2 @@\n ctx\n+d\n");
        $rows = $doc->splitRows();

        $this->assertSame(['kind' => DiffRowKind::Pair, 'hunk' => 0, 'left' => 0, 'right' => 2], $rows[1]);
        $this->assertSame(['kind' => DiffRowKind::Pair, 'hunk' => 0, 'left' => 1, 'right' => -1], $rows[2]);
        $this->assertSame(['kind' => DiffRowKind::Pair, 'hunk' => 1, 'left' => -1, 'right' => 1], $rows[5], 'a run of additions with no deletions sits right-only');
    }

    public function testSplitRowsNoNewlineMarkerRidesItsSide(): void
    {
        // The marker after +new terminates the ADDITION → right side; after a
        // deletion (old side ends the file) it rides left.
        $doc = DiffDocument::parse("@@ -1,1 +1,1 @@\n-old\n+new\n\\ No newline at end of file\n");
        $rows = $doc->splitRows();
        $this->assertSame(['kind' => DiffRowKind::Pair, 'hunk' => 0, 'left' => -1, 'right' => 2], $rows[2]);

        $doc = DiffDocument::parse("@@ -1,1 +1,1 @@\n-old\n\\ No newline at end of file\n+new\n");
        $rows = $doc->splitRows();
        $this->assertSame(['kind' => DiffRowKind::Pair, 'hunk' => 0, 'left' => 1, 'right' => -1], $rows[2], 'after a deletion the marker rides the old side');
    }

    public function testSplitRowsOfAnEmptyDocument(): void
    {
        $this->assertSame([], DiffDocument::parse('')->splitRows());
    }
}
