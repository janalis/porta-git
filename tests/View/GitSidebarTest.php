<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\View;

use PHPUnit\Framework\TestCase;
use Porta\GitPlugin\View\GitSidebar;
use Porta\GitPlugin\Widget\ChangesListWidget;
use Porta\GitPlugin\Widget\CommitBoxWidget;
use Porta\GitPlugin\Widget\GitTabsWidget;
use Porta\GitPlugin\Widget\HistoryListWidget;
use Porta\I18n\Translator;

final class GitSidebarTest extends TestCase
{
    public function testFansTheWidthOutToAllFourWidgetsWithClamping(): void
    {
        $tabs = new GitTabsWidget(new Translator('en'));
        $changes = new ChangesListWidget(new Translator('en'));
        $commitBox = new CommitBoxWidget(new Translator('en'));
        $history = new HistoryListWidget(new Translator('en'));
        $sidebar = new GitSidebar($tabs, $changes, $commitBox, $history);

        $this->assertSame(44, $sidebar->width(), 'the default ctor width');

        $sidebar->setWidth(60);
        $this->assertSame(60, $sidebar->width());
        $this->assertSame(60, $tabs->width());
        $this->assertSame(60, $changes->width());
        $this->assertSame(60, $commitBox->width());
        $this->assertSame(60, $history->width());

        $sidebar->setWidth(1); // clamps to the widgets' floor
        $this->assertSame(24, $sidebar->width());
        $sidebar->setWidth(24); // same width again: a no-op
        $this->assertSame(24, $history->width());
        $sidebar->setWidth(9_999);
        $this->assertSame(200, $sidebar->width(), 'clamped to the ceiling');
    }
}
