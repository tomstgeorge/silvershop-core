<?php

namespace SilverShop\Payment;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

interface WebhookCapableGateway extends ModernPaymentGateway
{
    /**
     * Handle an incoming webhook request from the payment provider.
     */
    public function handleWebhook(HTTPRequest $request): HTTPResponse;
}
