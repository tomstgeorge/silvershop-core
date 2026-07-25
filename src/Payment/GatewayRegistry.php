<?php

namespace SilverShop\Payment;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Omnipay\GatewayInfo;

class GatewayRegistry
{
    use Injectable;
    use Configurable;

    /**
     * Map of modern gateway identifiers to display names.
     * Configured via YAML:
     *   SilverShop\Payment\GatewayRegistry:
     *     modern_gateways:
     *       stripe_elements: 'Credit Card (Stripe)'
     */
    private static array $modern_gateways = [];

    /**
     * Get all available gateways (Omnipay legacy + modern), keyed by identifier => display name.
     */
    public function getAvailableGateways(): array
    {
        return array_merge(
            GatewayInfo::getSupportedGateways(),
            $this->getModernGatewayNames()
        );
    }

    /**
     * Get modern gateway display names keyed by identifier.
     */
    public function getModernGatewayNames(): array
    {
        return static::config()->get('modern_gateways') ?: [];
    }

    /**
     * Check if a gateway identifier refers to a modern (non-Omnipay) gateway.
     */
    public function isModernGateway(string $identifier): bool
    {
        $modern = $this->getModernGatewayNames();
        return isset($modern[$identifier]);
    }

    /**
     * Resolve the ModernPaymentGateway instance for the given identifier.
     */
    public function getGateway(string $identifier): ?ModernPaymentGateway
    {
        if (!$this->isModernGateway($identifier)) {
            return null;
        }

        $serviceName = ModernPaymentGateway::class . '.' . $identifier;
        if (Injector::inst()->has($serviceName)) {
            return Injector::inst()->get($serviceName);
        }

        return null;
    }

    /**
     * Delegates to GatewayInfo for legacy gateways; modern gateways are never offsite
     * unless they handle it via redirectUrl in PaymentResult.
     */
    public function isOffsite(string $identifier): bool
    {
        if ($this->isModernGateway($identifier)) {
            return false;
        }

        return GatewayInfo::isOffsite($identifier);
    }

    /**
     * Delegates to GatewayInfo for legacy gateways; modern gateways are never manual.
     */
    public function isManual(string $identifier): bool
    {
        if ($this->isModernGateway($identifier)) {
            return false;
        }

        return GatewayInfo::isManual($identifier);
    }

    /**
     * Check if a gateway is supported (either legacy or modern).
     */
    public function isSupported(string $identifier): bool
    {
        if ($this->isModernGateway($identifier)) {
            return $this->getGateway($identifier) !== null;
        }

        return GatewayInfo::isSupported($identifier);
    }
}
