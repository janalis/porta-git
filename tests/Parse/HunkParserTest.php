<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Parse;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Parse\HunkParser;

final class HunkParserTest extends TestCase
{
    public function testEmptyDiffProducesNoMarks(): void
    {
        $this->assertSame([], HunkParser::parse(''));
    }

    public function testContentLinesWithNoHunkHeaderAreIgnored(): void
    {
        $diff = "diff --git a/f.php b/f.php\nindex abc..def 100644\n--- a/f.php\n+++ b/f.php\n+added line\n-removed line\n";

        $this->assertSame([], HunkParser::parse($diff));
    }

    public function testSingleLineHunkWithoutExplicitCountsDefaultsToOne(): void
    {
        // `@@ -5 +5 @@` (no `,count`) means one line on each side.
        $marks = HunkParser::parse("@@ -5 +5 @@\n context\n");

        $this->assertSame(['modified'], \array_values(\array_intersect_key($marks, [4 => true])));
        $this->assertSame([4 => 'modified'], $marks);
    }

    public function testOverlappingLinesAreModifiedAndExtraLinesAreAdded(): void
    {
        // old has 1 line, new has 3: line 0 overlaps (modified), lines 1-2 are new (added).
        $marks = HunkParser::parse("@@ -1,1 +1,3 @@\n");

        $this->assertSame(['modified', 'added', 'added'], [$marks[0], $marks[1], $marks[2]]);
    }

    public function testPureAdditionMarksEveryNewLineAsAdded(): void
    {
        // old count 0: nothing overlaps, every new line is a pure addition.
        $marks = HunkParser::parse("@@ -0,0 +1,3 @@\n");

        $this->assertSame(['added', 'added', 'added'], [$marks[0], $marks[1], $marks[2]]);
    }

    public function testPureDeletionFlagsTheSurvivingLineAtNewStart(): void
    {
        // new count 0: a pure deletion, flagged on the line it sits after.
        $marks = HunkParser::parse("@@ -5,3 +4,0 @@\n");

        $this->assertSame(['deleted'], \array_values($marks));
        $this->assertSame(3, \array_key_first($marks));
    }

    public function testPureDeletionAtTheVeryStartOfTheFileClampsToLineZero(): void
    {
        // newStart=0 with newCount=0: deleting the file's first lines. Without
        // the max(0, ...) floor this would try to flag line -1.
        $marks = HunkParser::parse("@@ -1,3 +0,0 @@\n");

        $this->assertSame([0 => 'deleted'], $marks);
    }

    public function testMultipleHunksInOneDiffAreAllParsed(): void
    {
        $diff = "@@ -1,1 +1,1 @@\n context\n@@ -10,0 +11,2 @@\n+new1\n+new2\n";

        $marks = HunkParser::parse($diff);

        $this->assertSame('modified', $marks[0]);
        $this->assertSame('added', $marks[10]);
        $this->assertSame('added', $marks[11]);
    }

    public function testLaterHunkForTheSameLineOverwritesAnEarlierMark(): void
    {
        // Two headers claiming the same new-side line: the later one wins,
        // proving marks accumulate by simple overwrite, not by first-match.
        $marks = HunkParser::parse("@@ -1,5 +1,1 @@\n@@ -1,0 +1,1 @@\n");

        $this->assertSame(['added'], \array_values($marks));
    }
}
