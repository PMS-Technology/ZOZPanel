<?php

namespace Everest\Services\Billing\Channels;

use Stripe\StripeClient;
use Everest\Models\Billing\Order;
use Everest\Exceptions\DisplayException;
use Everest\Enums\Billing\PaymentChannel;
use Everest\Services\Billing\PaymentService;
use Everest\Services\Billing\CheckoutContext;
use Everest\Services\Billing\CreateOrderService;
use Everest\Services\Billing\PaymentFulfillmentService;

class StripeChannel implements Channel
{
    public function __construct(
        private PaymentService $paymentService,
        private CreateOrderService $orderService,
        private PaymentFulfillmentService $fulfillmentService,
    ) {
    }

    public function channel(): PaymentChannel
    {
        return PaymentChannel::Stripe;
    }

    public function create(CheckoutContext $context, ChannelRoute $route): CheckoutTarget
    {
        $stripe = $this->client();

        $transaction = $this->paymentService->create(
            $stripe,
            $context->user,
            $context->product,
            $context->metadata,
            $context->price,
            $context->deploymentFee,
        );

        $this->orderService->create(
            $transaction->id,
            $context->user,
            $context->product,
            Order::STATUS_PENDING,
            $context->orderType(),
            $context->price,
            $context->orderMetadata(),
        );

        return new CheckoutTarget($transaction->url);
    }

    public function process(string $identifier): Order
    {
        $stripe = $this->client();

        try {
            $transaction = $stripe->checkout->sessions->retrieve($identifier);
        } catch (\Throwable $exception) {
            throw new DisplayException('Failed to process order: unable to retrieve session');
        }

        if ($transaction->payment_status !== 'paid') {
            throw new DisplayException('Payment not completed.');
        }

        $metadata = (array) $transaction->metadata;

        $order = Order::where('transaction_id', $transaction->id)->firstOrFail();

        $this->fulfillmentService->fulfill(
            $order,
            $metadata,
            $transaction->amount_total !== null ? ((float) $transaction->amount_total) / 100 : null,
            $transaction->currency !== null ? (string) $transaction->currency : null,
        );

        return $order->fresh();
    }

    /**
     * The Stripe client the channel talks to.
     *
     * The client is built from the channel's secret, but a bound client wins, so the
     * gateway can be swapped out without reaching Stripe over the network.
     */
    private function client(): StripeClient
    {
        return app()->bound(StripeClient::class)
            ? app(StripeClient::class)
            : new StripeClient((string) $this->channel()->setting('secret'));
    }
}
