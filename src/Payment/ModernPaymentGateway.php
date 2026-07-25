<?php

namespace SilverShop\Payment;

use SilverShop\Model\Order;
use SilverStripe\Forms\FieldList;

interface ModernPaymentGateway
{
    /**
     * Unique identifier matching the YAML config key (e.g. 'stripe_elements').
     */
    public function getIdentifier(): string;

    /**
     * Human-readable name for display in checkout.
     */
    public function getDisplayName(): string;

    /**
     * Form fields to render in the checkout (e.g. a container div for Stripe Elements).
     */
    public function getFormFields(Order $order): FieldList;

    /**
     * Inject JS/CSS requirements for this gateway.
     * Implementations should call Requirements::javascript() / Requirements::css().
     */
    public function getRequirements(Order $order): void;

    /**
     * Server-side processing after client-side payment confirmation.
     * Receives submitted form data (including payment token/intent from client JS) and the order.
     */
    public function processPayment(array $data, Order $order): PaymentResult;
}
