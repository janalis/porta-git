<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\ChangeEntry;

final class ChangeEntryTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> porcelain XY => unmerged? */
    public static function statuses(): iterable
    {
        // git status' seven unmerged codes …
        foreach (['DD', 'AU', 'UD', 'UA', 'DU', 'AA', 'UU'] as $code) {
            yield '['.$code.']' => [$code, true];
        }
        // … and the ordinary ones that share their letters.
        foreach (['M ', ' M', 'MM', 'A ', 'AM', 'D ', ' D', 'R ', '??'] as $code) {
            yield '['.$code.']' => [$code, false];
        }
    }

    #[DataProvider('statuses')]
    public function testAConflictIsAnyUnmergedCode(string $xy, bool $unmerged): void
    {
        $this->assertSame($unmerged, new ChangeEntry('a.php', $xy[0], $xy[1])->isUnmerged());
    }
}
