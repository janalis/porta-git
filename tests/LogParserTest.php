<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\LogParser;

final class LogParserTest extends TestCase
{
    public function testParsesNulRecordsWithUnitSeparatedFields(): void
    {
        $raw = "abc123full\x1fabc123\x1fAlice\x1f1700000000\x1fFix the parser\0def456full\x1fdef456\x1fBob\x1f1700000100\x1fAdd tests\0";

        $entries = LogParser::parseLog($raw);

        $this->assertCount(2, $entries);
        $this->assertSame(['abc123full', 'abc123', 'Alice', 1_700_000_000, 'Fix the parser'], [
            $entries[0]->sha,
            $entries[0]->shortSha,
            $entries[0]->author,
            $entries[0]->timestamp,
            $entries[0]->subject,
        ]);
        $this->assertSame('Add tests', $entries[1]->subject);
    }

    public function testMalformedLogRecordsAreSkipped(): void
    {
        $this->assertSame([], LogParser::parseLog(''));
        $this->assertSame([], LogParser::parseLog("no-separators\0"));
        $this->assertCount(1, LogParser::parseLog("junk\0a\x1fb\x1fc\x1f5\x1fd\0"));
    }

    public function testParsesNameStatusIncludingRenames(): void
    {
        $raw = "M\0src/a.php\0A\0new.txt\0R100\0old/name.php\0new/name.php\0D\0gone.txt\0";

        $files = LogParser::parseNameStatus($raw);

        $this->assertSame(
            [
                ['status' => 'M', 'path' => 'src/a.php'],
                ['status' => 'A', 'path' => 'new.txt'],
                ['status' => 'R', 'path' => 'new/name.php'],
                ['status' => 'D', 'path' => 'gone.txt'],
            ],
            $files,
        );
    }

    public function testNameStatusEdgeCases(): void
    {
        $this->assertSame([], LogParser::parseNameStatus(''));
        $this->assertSame([], LogParser::parseNameStatus("M\0"), 'trailing status without a path');
        $this->assertSame([['status' => 'C', 'path' => 'copy.txt']], LogParser::parseNameStatus("C75\0orig.txt\0copy.txt\0"));
    }

    public function testParseStashListHappyAndMalformed(): void
    {
        $raw = "stash@{0}\x1f1700000000\x1fWIP on main: 1234abc fix\0stash@{1}\x1f1690000000\x1fOn feature: spike\0";
        $this->assertSame(
            [
                ['ref' => 'stash@{0}', 'timestamp' => 1_700_000_000, 'subject' => 'WIP on main: 1234abc fix'],
                ['ref' => 'stash@{1}', 'timestamp' => 1_690_000_000, 'subject' => 'On feature: spike'],
            ],
            LogParser::parseStashList($raw),
        );

        $this->assertSame([], LogParser::parseStashList(''));
        $this->assertSame([], LogParser::parseStashList("only-two\x1ffields\0"), 'short records are skipped');
        $this->assertSame([], LogParser::parseStashList("\x1f1\x1fempty ref\0"), 'an empty ref is skipped');
    }
}
