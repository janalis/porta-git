<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Widget\FileRow;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/** One changed file's row: what gives way, and in what order, as the width runs out. */
final class FileRowTest extends TestCase
{
    /** Narrower than the letter: the letter cut to the width, not the row past it. */
    public function testNarrowerThanTheLetterIsTheLetterCut(): void
    {
        $this->assertSame(' …', self::row('src/a.php', 2));
    }

    /** Two columns of folder are room for the ellipsis and the slash; one is not. */
    public function testTwoColumnsOfFolderStillSayThereIsOne(): void
    {
        $this->assertSame('…/x.php M ', self::row('abc/x.php', 10));
        $this->assertSame('abc/x.php M ', self::row('abc/x.php', 12), 'the folder whole once it fits');
        $this->assertSame('x.php  M ', self::row('abc/x.php', 9), 'one column: the name alone');
    }

    /** The folder's end is kept by character, never by byte. */
    public function testAFoldersTailIsCutByCharacter(): void
    {
        $this->assertSame('…sé/x.php M ', self::row('dossé/x.php', 12));
    }

    private static function row(string $path, int $width): string
    {
        return AnsiUtils::stripAnsiCodes(FileRow::render($path, 'M', $width, Styles::dark()));
    }
}
