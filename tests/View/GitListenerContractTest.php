<?php

declare(strict_types=1);

namespace Porta\GitPlugin\Tests\View;

use PHPUnit\Framework\TestCase;
use Porta\Event\PluginObservable;
use Porta\GitPlugin\GitRefresher;
use Porta\GitPlugin\View\GitViewController;
use Porta\GitPlugin\View\GitWiring;

/**
 * The eighteen git reactions, pinned against the methods that answer them.
 *
 * They were `#[AsEventListener]` attributes the container read — a feature that
 * evaporated the moment these classes stopped being services, and it evaporates
 * SILENTLY: nothing throws, a reaction simply never happens, and only a test
 * that walked that exact path would notice. `EventListenerWiringTest` asserts
 * every event in `src/Event` has somewhere to land, and fourteen of these
 * events left `src/Event` with the plugin, so that guarantee went too.
 *
 * `plugin.php` now registers by looping {@see GitWiring}, so the map IS the
 * wiring — there is no second list to drift from. What this test adds is that
 * the map names real methods with matching signatures, and that no `on*`
 * handler exists which the map forgot: hand-registering seventeen of eighteen
 * is a working IDE with one dead interaction.
 */
final class GitListenerContractTest extends TestCase
{
    public function testEveryWiredMethodExistsAndTakesTheEventItIsMappedTo(): void
    {
        // The half that catches a typo'd method name or an event moved to a
        // handler that cannot take it — both of which are a fatal at the first
        // dispatch, i.e. mid-session rather than at boot.
        foreach ([
            GitViewController::class => [...GitWiring::VIEW, ...GitWiring::VIEW_CORE],
            GitRefresher::class => GitWiring::REFRESHER,
        ] as $class => $map) {
            foreach ($map as $event => $method) {
                $this->assertTrue(\method_exists($class, $method), "{$class}::{$method}() is wired but does not exist");
                $parameters = new \ReflectionMethod($class, $method)->getParameters();
                $this->assertCount(1, $parameters, "{$class}::{$method}() must take exactly the event");
                $type = $parameters[0]->getType();
                $this->assertInstanceOf(\ReflectionNamedType::class, $type);
                $this->assertSame($event, $type->getName(), "{$class}::{$method}() takes the wrong event");
            }
        }
    }

    public function testNoHandlerExistsThatTheMapForgot(): void
    {
        // The other direction, and the one that matters after the move: an
        // `on*(SomeEvent)` method nobody registers is a reaction that silently
        // never fires. The old attribute-based check could not miss this
        // because declaring the listener and writing the method were one act.
        foreach ([
            GitViewController::class => [...GitWiring::VIEW, ...GitWiring::VIEW_CORE],
            GitRefresher::class => GitWiring::REFRESHER,
        ] as $class => $map) {
            $this->assertSame([], \array_values(\array_diff(self::eventHandlersOf($class), \array_values($map))), $class.': handlers nothing registers');
        }
    }

    public function testTheSetIsTwentyThreeReactionsAcrossTwoClasses(): void
    {
        // A count as well as a map: it is the number to check the registration
        // against at a glance, and it makes "one of these was dropped" a
        // visible arithmetic failure rather than a diff to read.
        //
        // Nineteen before the move. The one that left is `onBufferSaved`:
        // `BufferSaved` carries a mutable `Buffer`, so it is not observable by
        // a plugin, and `PluginApi::onFileSaved()` is the sanctioned spelling.
        // Back to nineteen with the changes list's stage-all box; twenty-three
        // with the toolbar, whose menu cells ask the controller and which
        // follows the working tree moving under the open view, the commit
        // header's copy button, and the diff's settings gear.
        $total = \count(GitWiring::VIEW) + \count(GitWiring::VIEW_CORE) + \count(GitWiring::REFRESHER);

        $this->assertSame(23, $total);
        $this->assertCount(18, GitWiring::VIEW, 'the widget → controller set, on this plugin’s own dispatcher');
    }

    public function testOnlyPluginObservableEventsTravelThroughTheCoreBus(): void
    {
        // `PluginApi::on()` refuses anything else, so a core event added to the
        // wrong map is a plugin that fails to load — loud, but only at runtime
        // and only in a repository. This catches it here instead.
        foreach (\array_keys([...GitWiring::VIEW_CORE, ...GitWiring::REFRESHER]) as $event) {
            $this->assertTrue(\is_a($event, PluginObservable::class, allow_string: true), $event.' is not plugin-observable');
        }
        // Its own, and the diff pane's — the pane moved to the diff plugin
        // with its events, and git, which requires it, still answers them.
        foreach (\array_keys(GitWiring::VIEW) as $event) {
            $this->assertMatchesRegularExpression('/^Porta\\\\(GitPlugin|DiffPlugin)\\\\Event\\\\/', $event, 'the view bus carries plugin events only, never core’s');
        }
    }

    /**
     * Every `on<Something>(SomeEvent)` public method on $class.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function eventHandlersOf(string $class): array
    {
        $found = [];
        foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!\str_starts_with($method->getName(), 'on')) {
                continue;
            }
            $parameters = $method->getParameters();
            if (1 !== \count($parameters)) {
                continue;
            }
            $type = $parameters[0]->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $found[] = $method->getName();
            }
        }

        return $found;
    }
}
