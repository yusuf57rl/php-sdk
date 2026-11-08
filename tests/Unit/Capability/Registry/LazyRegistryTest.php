<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Registry;

use Mcp\Capability\Registry;
use Mcp\Capability\Registry\LazyRegistry;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Tool;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Subscription\PublishingEventDispatcher;
use PHPUnit\Framework\TestCase;

final class LazyRegistryTest extends TestCase
{
    public function testLoadsOnceOnFirstRead(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->once())->method('load');

        $registry = new LazyRegistry(new Registry(), $loader);

        $registry->getTools();
        $registry->hasTools();
    }

    public function testWritesAndListChangeSupportDoNotLoad(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->never())->method('load');

        $registry = new LazyRegistry(new Registry(), $loader);

        $registry->registerTool($this->tool('alpha'), static fn (): string => 'ok');
        $registry->unregisterTool('alpha');
        $registry->publishesListChanges();
    }

    public function testFailedLoadIsRetriedOnTheNextRead(): void
    {
        $loader = $this->createMock(LoaderInterface::class);
        $loader->expects($this->exactly(2))
            ->method('load')
            ->willReturnCallback(static function () {
                static $calls = 0;
                if (1 === ++$calls) {
                    throw new RuntimeException('source not ready');
                }
            });

        $registry = new LazyRegistry(new Registry(), $loader);

        try {
            $registry->getTools();
            $this->fail('The load failure must reach the reader.');
        } catch (RuntimeException) {
        }

        $this->assertSame([], $registry->getTools()->references);
    }

    public function testExposesWhatTheLoaderWroteToTheWrappedRegistry(): void
    {
        $inner = new Registry();
        $registry = new LazyRegistry($inner, $this->writing($this->tool('alpha')));

        $this->assertFalse($inner->hasTool('alpha'));
        $this->assertSame('alpha', $registry->getTool('alpha')->tool->name);
        $this->assertTrue($inner->hasTool('alpha'));
    }

    public function testLoadIsNotAnnouncedAsAChangeByADeferredRegistry(): void
    {
        $bus = new InMemoryNotificationBus();
        $registry = new LazyRegistry(new Registry(new PublishingEventDispatcher($bus)), $this->writing($this->tool('alpha')));

        $registry->load();

        $this->assertTrue($registry->hasTool('alpha'));
        $this->assertSame(0, $bus->cursor());
    }

    public function testLoadsAThirdPartyRegistryThroughThePlainLoader(): void
    {
        $inner = $this->createMock(RegistryInterface::class);
        $inner->expects($this->once())->method('registerTool');
        $inner->method('hasTools')->willReturn(true);

        $registry = new LazyRegistry($inner, $this->writing($this->tool('alpha')));

        $this->assertTrue($registry->hasTools());
    }

    private function tool(string $name): Tool
    {
        return new Tool(name: $name, title: null, inputSchema: ['type' => 'object', 'properties' => [], 'required' => null], description: null, annotations: null);
    }

    private function writing(Tool $tool): LoaderInterface
    {
        return new class($tool) implements LoaderInterface {
            public function __construct(private Tool $tool)
            {
            }

            public function load(RegistryInterface $registry): void
            {
                $registry->registerTool($this->tool, static fn (): string => 'ok');
            }
        };
    }
}
