<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Diff;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Diff\DiffDocument;
use Porta\GitPlugin\Diff\IntralineDiff;

final class IntralineDiffTest extends TestCase
{
    public function testCommonPrefixAndSuffixBoundTheChange(): void
    {
        // '$count = 1;' -> '$count = 22;' — the change is the '1' vs '22' middle.
        $ranges = IntralineDiff::changedRanges('$count = 1;', '$count = 22;');

        $this->assertSame([[9, 10], [9, 11]], $ranges);
    }

    public function testPureInsertionInTheMiddle(): void
    {
        $ranges = IntralineDiff::changedRanges('ab', 'aXb');

        $this->assertSame([[1, 1], [1, 2]], $ranges, 'empty old range at the insertion point');
    }

    public function testIdenticalAndDisjointLinesCarryNoRange(): void
    {
        $this->assertNull(IntralineDiff::changedRanges('same', 'same'));
        $this->assertNull(IntralineDiff::changedRanges('abc', 'xyz'), 'nothing in common: no emphasis');
    }

    public function testUtf8BoundariesAreNeverSplit(): void
    {
        // 'héllo' -> 'hállo': é (c3a9) vs á (c3a1) share the lead byte c3; the
        // prefix must back off to the char boundary.
        $ranges = IntralineDiff::changedRanges("h\u{e9}llo", "h\u{e1}llo");

        $this->assertNotNull($ranges);
        [$old, $new] = $ranges;
        $this->assertSame(1, $old[0], 'prefix backed off to before the multibyte char');
        $this->assertSame(3, $old[1]);
        $this->assertSame([1, 3], $new);
    }

    public function testSuffixBackoffNeverSplitsAMultibyteChar(): void
    {
        // é (c3 a9) vs ĩ (c4 a9): the byte suffix 'a9' matches but sits inside
        // both chars — the backoff must retreat to the boundary, leaving
        // nothing in common (→ no emphasis).
        $this->assertNull(IntralineDiff::changedRanges("\u{e9}", "\u{129}"));
    }

    public function testDiffDocumentPairsRunsAndStampsRanges(): void
    {
        $doc = DiffDocument::parse("@@ -1,3 +1,3 @@\n-\$a = 1;\n-gone entirely\n+\$a = 2;\n+arrived entirely\n ctx\n");

        [$del1, $del2, $add1] = $doc->hunks[0]->lines;
        $this->assertSame([5, 6], $del1->changedRange, 'paired first del: only the digit differs');
        $this->assertSame([5, 6], $add1->changedRange);
        $this->assertNotNull($del2->changedRange, 'second pair shares the " entirely" suffix');
        $this->assertNull($doc->hunks[0]->lines[4]->changedRange, 'context lines never carry a range');
    }

    public function testUnpairedLinesCarryNoRange(): void
    {
        $doc = DiffDocument::parse("@@ -1,1 +1,2 @@\n-old\n+new one\n+extra addition\n");

        $this->assertNull($doc->hunks[0]->lines[0]->changedRange, 'disjoint pair: no emphasis');
        $this->assertNull($doc->hunks[0]->lines[2]->changedRange, 'the surplus addition has no partner');
    }

    public function testSurplusDeletionsHaveNoPartner(): void
    {
        // The mirror of the surplus-addition case: more deletions than
        // additions in the run — only the first min(dels, adds) pair up.
        $doc = DiffDocument::parse("@@ -1,2 +1,1 @@\n-\$a = 1;\n-extra removed\n+\$a = 2;\n");

        [$del1, $del2, $add1] = $doc->hunks[0]->lines;
        $this->assertSame([5, 6], $del1->changedRange, 'paired first del: only the digit differs');
        $this->assertSame([5, 6], $add1->changedRange);
        $this->assertNull($del2->changedRange, 'the surplus deletion has no partner');
    }

    public function testTwoSeparateRunsInOneHunkEachPairIndependently(): void
    {
        // Two ±runs split by a context line: the loop must resume scanning
        // for the second run's own start, not get stuck after the first.
        $doc = DiffDocument::parse("@@ -1,4 +1,4 @@\n-\$a = 1;\n+\$a = 2;\n ctx\n-\$b = 3;\n+\$b = 4;\n");

        [$del1, $add1, , $del2, $add2] = $doc->hunks[0]->lines;
        $this->assertSame([5, 6], $del1->changedRange);
        $this->assertSame([5, 6], $add1->changedRange);
        $this->assertSame([5, 6], $del2->changedRange, 'the second run pairs independently of the first');
        $this->assertSame([5, 6], $add2->changedRange);
    }
}
