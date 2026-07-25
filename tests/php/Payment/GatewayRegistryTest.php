<?php

namespace SilverShop\Tests\Payment;

use SilverShop\Payment\GatewayRegistry;
use SilverShop\Payment\ModernPaymentGateway;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

class GatewayRegistryTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(GatewayRegistry::class, 'modern_gateways', [
            'mock_modern' => 'Mock Modern Gateway',
        ]);

        Injector::inst()->registerService(
            new MockModernGateway(),
            ModernPaymentGateway::class . '.mock_modern'
        );
    }

    public function testIsModernGateway(): void
    {
        $registry = GatewayRegistry::singleton();
        $this->assertTrue($registry->isModernGateway('mock_modern'));
        $this->assertFalse($registry->isModernGateway('Dummy'));
        $this->assertFalse($registry->isModernGateway('nonexistent'));
    }

    public function testGetGateway(): void
    {
        $registry = GatewayRegistry::singleton();
        $gateway = $registry->getGateway('mock_modern');
        $this->assertInstanceOf(ModernPaymentGateway::class, $gateway);
        $this->assertEquals('mock_modern', $gateway->getIdentifier());
    }

    public function testGetGatewayReturnsNullForLegacy(): void
    {
        $registry = GatewayRegistry::singleton();
        $this->assertNull($registry->getGateway('Dummy'));
    }

    public function testGetAvailableGatewaysIncludesModern(): void
    {
        $registry = GatewayRegistry::singleton();
        $gateways = $registry->getAvailableGateways();
        $this->assertArrayHasKey('mock_modern', $gateways);
        $this->assertEquals('Mock Modern Gateway', $gateways['mock_modern']);
    }

    public function testIsOffsiteForModernGateway(): void
    {
        $registry = GatewayRegistry::singleton();
        $this->assertFalse($registry->isOffsite('mock_modern'));
    }

    public function testIsManualForModernGateway(): void
    {
        $registry = GatewayRegistry::singleton();
        $this->assertFalse($registry->isManual('mock_modern'));
    }

    public function testIsSupportedForModernGateway(): void
    {
        $registry = GatewayRegistry::singleton();
        $this->assertTrue($registry->isSupported('mock_modern'));
    }

    public function testIsSupportedReturnsFalseForUnregisteredModern(): void
    {
        Config::modify()->set(GatewayRegistry::class, 'modern_gateways', [
            'unregistered_gateway' => 'Unregistered',
        ]);

        $registry = GatewayRegistry::singleton();
        // It's in the config but has no Injector service registered
        $this->assertFalse($registry->isSupported('unregistered_gateway'));
    }
}
