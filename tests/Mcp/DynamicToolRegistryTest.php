<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\AffordanceStore;
use App\Mcp\DiscoveryModeResolver;
use App\Mcp\DynamicToolRegistry;
use App\Mcp\RestoringRegistryDecorator;
use App\Mcp\StoredAffordance;
use Mcp\Capability\Registry;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionStoreInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class DynamicToolRegistryTest extends TestCase
{
    public function testDiscoveredToolsCanBeRestoredAndUnregistered(): void
    {
        $sessionStore = $this->createStub(SessionStoreInterface::class);
        $store = new AffordanceStore($sessionStore, new RequestStack());
        $registry = new Registry();
        $dynamic = new DynamicToolRegistry($registry, $store);
        $affordance = new StoredAffordance('read_vehicle', 'Read vehicle', ['type' => 'object'], 'operation', '/vehicles/1', method: 'GET');

        self::assertTrue($dynamic->registerAffordance($affordance, new Session($sessionStore)));
        self::assertSame('read_vehicle', $registry->getTool('read_vehicle')->tool->name);
        self::assertFalse($dynamic->registerAffordance($affordance, new Session($sessionStore)));

        $restoredRegistry = new Registry();
        $restored = new RestoringRegistryDecorator($restoredRegistry, new DynamicToolRegistry($restoredRegistry, $store), new DiscoveryModeResolver());
        self::assertTrue($restored->hasTool('read_vehicle'));
        self::assertSame('read_vehicle', $restored->getTool('read_vehicle')->tool->name);
        $restored->unregisterTool('read_vehicle');
        self::assertFalse($restored->hasTool('read_vehicle'));
    }
}
