<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\Widget\DiffColumn;
use Porta\GitPlugin\Widget\DiffGeometry;

final class DiffGeometryTest extends TestCase
{
    public function testTextStartPerColumnAndTheSplitBoundary(): void
    {
        $this->assertSame(39, DiffGeometry::splitBoundary(80), 'the divider column');

        $this->assertSame(12, DiffGeometry::textStart(null, 80), 'unified: past the dual gutters');
        $this->assertSame(6, DiffGeometry::textStart(DiffColumn::Old, 80), 'left half gutter');
        $this->assertSame(46, DiffGeometry::textStart(DiffColumn::New, 80), 'divider + right gutter');
    }

    public function testMouseAndRenderCanNoLongerDisagree(): void
    {
        // The regression this class exists to prevent: both paths read the
        // same origin, so a drag's highlight matches its selection per width.
        foreach ([41, 80, 121] as $width) {
            $this->assertSame(
                DiffGeometry::splitBoundary($width) + 7,
                DiffGeometry::textStart(DiffColumn::New, $width),
            );
        }
    }
}
