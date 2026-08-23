<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Parse;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\ChangeEntry;
use Porta\GitPlugin\Parse\StatusParser;

final class StatusParserTest extends TestCase
{
    public function testParsesTheCommonStatusShapes(): void
    {
        $raw = " M src/a.php\0M  src/b.php\0MM src/c.php\0?? notes.md\0A  new.php\0 D gone.php\0";

        $entries = StatusParser::parse($raw);

        $this->assertCount(6, $entries);
        [$a, $b, $c, $notes, $new, $gone] = $entries;

        $this->assertSame(['src/a.php', ' ', 'M'], [$a->path, $a->indexStatus, $a->worktreeStatus]);
        $this->assertSame('none', $a->stagedState(), 'worktree-only change');
        $this->assertSame('full', $b->stagedState(), 'index-only change');
        $this->assertSame('partial', $c->stagedState(), 'both sides dirty');
        $this->assertTrue($notes->isUntracked());
        $this->assertSame('none', $notes->stagedState());
        $this->assertSame('A', $notes->displayStatus(), 'untracked displays as an addition');
        $this->assertSame('full', $new->stagedState());
        $this->assertSame('D', $gone->displayStatus());
    }

    public function testRenameCarriesTheOriginalPath(): void
    {
        $raw = "R  new/name.php\0old/name.php\0 M other.php\0";

        $entries = StatusParser::parse($raw);

        $this->assertCount(2, $entries);
        $this->assertSame('new/name.php', $entries[0]->path);
        $this->assertSame('old/name.php', $entries[0]->renamedFrom);
        $this->assertSame('other.php', $entries[1]->path, 'the extra rename token is consumed, not misparsed');
    }

    public function testDisplayStatusPrefersTheWorktreeSide(): void
    {
        $entry = new ChangeEntry('x.php', 'A', 'M');
        $this->assertSame('M', $entry->displayStatus());
        $this->assertSame('A', new ChangeEntry('x.php', 'A', ' ')->displayStatus());
    }

    public function testMalformedAndEmptyInputYieldNothing(): void
    {
        $this->assertSame([], StatusParser::parse(''));
        $this->assertSame([], StatusParser::parse("\0"));
        $this->assertSame([], StatusParser::parse("junk-without-space-at-2\0xy\0"));
    }

    public function testARenameAsTheLastRecordHasNoOriginalPathToken(): void
    {
        // Nothing follows the rename record but the trailing empty split
        // artifact — must not treat it as (or crash on) a real original path.
        $entries = StatusParser::parse("R  new/name.php\0");

        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]->renamedFrom);
    }
}
