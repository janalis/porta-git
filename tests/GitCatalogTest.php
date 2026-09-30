<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\UsesBundledPlugins;

/**
 * Git's strings live in this plugin's own `translations/`, and the IDE serves
 * them once the plugin loads.
 *
 * In French, because every other test here runs in English, where a string
 * reads the same translated or not: a catalog that stopped loading would
 * pass them all.
 */
final class GitCatalogTest extends TestCase
{
    use BootsIde;
    use UsesBundledPlugins;

    public function testABootedIdeServesGitsOwnCatalog(): void
    {
        $ide = $this->bootIde([
            'composer.json' => '{}',
            '.porta.json' => '{"enabledPlugins":["git"],"settings":{"language":"fr"}}',
        ]);

        $translator = Services::resolve($ide, Translator::class);

        $this->assertSame('fr', $translator->locale());
        $this->assertSame('Arbre de travail propre', $translator->trans('Working tree clean'));
    }
}
