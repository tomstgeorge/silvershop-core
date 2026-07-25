<?php

namespace SilverShop\Payment;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

class WebhookController extends Controller
{
    private static string $url_segment = 'shop-webhook';

    private static array $allowed_actions = [
        'handleGateway',
    ];

    private static array $url_handlers = [
        '$Gateway!' => 'handleGateway',
    ];

    public function handleGateway(HTTPRequest $request): HTTPResponse
    {
        $gatewayId = $request->param('Gateway');
        $registry = GatewayRegistry::singleton();
        $gateway = $registry->getGateway($gatewayId);

        if (!$gateway instanceof WebhookCapableGateway) {
            return $this->httpError(404, 'Gateway not found or does not support webhooks');
        }

        return $gateway->handleWebhook($request);
    }
}
