<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Widget;

use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A changed file as GitHub Desktop lists one: the folder dimmed, the name in
 * full, and the status letter flush right in its tint. The changes list and
 * a commit's file list both draw it, so a path reads the same in either.
 */
final class FileRow
{
    /** @var array<string, Role> each status letter's tint; a letter without one (an unmerged `U`) is drawn plain */
    private const array STATUS_ROLE = [
        'A' => Role::Added,
        'M' => Role::Modified,
        'D' => Role::Deleted,
        'R' => Role::Hunk,
        'C' => Role::Hunk,
    ];

    /** The status letter, tinted. */
    public static function status(string $status, Styles $styles): string
    {
        return \array_key_exists($status, self::STATUS_ROLE) ? $styles->of(self::STATUS_ROLE[$status])->apply($status) : $status;
    }

    /**
     * $path then $suffix, the status letter at the end, in exactly $width
     * columns. A path too long gives up the start of its folder first, then
     * the end of its name — never the letter.
     */
    public static function render(string $path, string $status, int $width, Styles $styles, string $suffix = ''): string
    {
        // A path is the repository's to name, control bytes and all: shown, never obeyed.
        $path = AnsiText::oneLine($path);
        $letter = ' '.self::status($status, $styles).' ';
        $room = $width - AnsiUtils::visibleWidth($letter);
        if ($room < 1) {
            return AnsiText::truncate($letter, $width, pad: true);
        }
        $slash = \strrpos($path, needle: '/');
        $folder = false === $slash ? '' : \substr($path, offset: 0, length: $slash + 1);
        $name = (false === $slash ? $path : \substr($path, $slash + 1)).$suffix;
        $nameWidth = AnsiUtils::visibleWidth($name);
        $folderRoom = $room - $nameWidth;
        $dim = $styles->of(Role::Dim);
        $label = match (true) {
            '' === $folder || $folderRoom >= AnsiUtils::visibleWidth($folder) => ('' === $folder ? '' : $dim->apply($folder)).$name,
            $folderRoom >= 2 => $dim->apply(Glyphs::ELLIPSIS.self::tail($folder, $folderRoom - 1)).$name,
            default => $name,
        };

        return AnsiText::truncate($label, $room, pad: true).$letter;
    }

    /** The end of $text, at most $width columns of it. */
    private static function tail(string $text, int $width): string
    {
        $kept = '';
        $used = 0;
        foreach (\array_reverse(\mb_str_split($text)) as $char) {
            $used += \mb_strwidth($char);
            if ($used > $width) {
                break;
            }
            $kept = $char.$kept;
        }

        return $kept;
    }
}
