<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Support;

use Porta\Ui\ScreenDensity;

/**
 * A density that has seen a short terminal: the one-row controls, the diff
 * toolbar's tags on its title row. A widget built bare is roomy, and the
 * tests written against one-row geometry say which screen they mean.
 */
final class CompactScreen
{
    public static function density(): ScreenDensity
    {
        $density = new ScreenDensity();
        $density->observe(24);

        return $density;
    }
}
