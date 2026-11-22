<?php

namespace Everest\Tests\Integration\Api\Client\Billing;

use Everest\Models\Node;
use Everest\Models\User;
use Stripe\StripeClient;
use Everest\Models\Billing\Order;
use Everest\Models\Billing\DiscountCode;
use Everest\Tests\Traits\Integration\CreatesBillingTestModels;
use Everest\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class CheckoutControllerCreateTest extends ClientApiIntegrationTestCase
{
    use CreatesBillingTestModels;

    public function setUp(): void
    {
        parent::setUp();

        $this->enableBilling();
    }

    protected function tearDown(): void
    {
        // Node cleanup is handled by the parent tearDown() (after Server, to
        // avoid an FK violation on servers.allocation_id -> allocations.node_id).
        $this->cleanupBillingModels();

        parent::tearDown();
    }

    /**
     * Binds a StripeClient into the container whose checkout.sessions.create returns
     * a fake session, so PaymentService::create() resolves it instead of calling Stripe.
     */
    private function stubSessionCreate(string $sessionId, string $url): void
    {
        $session = (object) ['id' => $sessionId, 'url' => $url];

        $sessions = \Mockery::mock();
        $sessions->shouldReceive('create')->andReturn($session);

        $client = \Mockery::mock(StripeClient::class);
        $client->shouldReceive('getService')->with('checkout')->andReturn(
            (object) ['sessions' => $sessions]
        );

        $this->app->instance(StripeClient::class, $client);
    }

    public function testDeploymentFeeIsCapturedInOrderMetadataAndAddedToTheTotal(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(['price' => 10.50]);
        $node = Node::factory()->create(['deployable' => true, 'deployment_fee' => 5.25]);

        $this->stubSessionCreate('cs_test_fee', 'https://checkout.stripe.test/fee');

        $response = $this->actingAs($user)->postJson('/api/client/billing/checkout/create', [
            'payment_method' => 'stripe',
            'product_id' => $product->id,
            'node_id' => $node->id,
        ]);

        $response->assertOk();
        $this->assertSame('https://checkout.stripe.test/fee', $response->json('url'));

        $order = Order::where('transaction_id', 'cs_test_fee')->firstOrFail();

        $this->assertSame(15.75, $order->total);
        // assertEquals (not assertSame): Order.metadata round-trips through a JSON
        // column, and PHP's json_encode drops the ".0" off whole-number floats.
        $this->assertEquals(5.25, $order->metadata['deployment_fee']);
        $this->assertNull($order->metadata['discount_code']);
    }

    public function testDiscountCodeIsCapturedInOrderMetadata(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(['price' => 20.50]);
        DiscountCode::create([
            'code' => 'SAVE5NOW',
            'description' => 'test discount',
            'type' => 'numeric',
            'value' => 5,
            'uses' => -1,
            'active' => true,
            'expires_at' => null,
        ]);

        $this->stubSessionCreate('cs_test_discount', 'https://checkout.stripe.test/discount');

        $response = $this->actingAs($user)->postJson('/api/client/billing/checkout/create', [
            'payment_method' => 'stripe',
            'product_id' => $product->id,
            'server_id' => $this->createServerModel(['user_id' => $user->id])->id,
            'discount_code' => 'SAVE5NOW',
        ]);

        $response->assertOk();

        $order = Order::where('transaction_id', 'cs_test_discount')->firstOrFail();

        $this->assertSame(15.50, $order->total);
        $this->assertSame('SAVE5NOW', $order->metadata['discount_code']);
        $this->assertEquals(20.50, $order->metadata['subtotal']);
    }

    public function testOrderMetadataHasNoFeeOrSubtotalWhenThereIsNeither(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct(['price' => 10.00]);
        $node = Node::factory()->create(['deployable' => true]);

        $this->stubSessionCreate('cs_test_plain', 'https://checkout.stripe.test/plain');

        $response = $this->actingAs($user)->postJson('/api/client/billing/checkout/create', [
            'payment_method' => 'stripe',
            'product_id' => $product->id,
            'node_id' => $node->id,
        ]);

        $response->assertOk();

        $order = Order::where('transaction_id', 'cs_test_plain')->firstOrFail();

        $this->assertSame(10.00, $order->total);
        // The checkout details are kept on the order, but the amounts that do not
        // apply are left out rather than stored as null.
        $this->assertArrayNotHasKey('deployment_fee', $order->metadata);
        $this->assertArrayNotHasKey('subtotal', $order->metadata);
        $this->assertSame('stripe', $order->metadata['payment_channel']);
    }

    public function testCannotCreateACheckoutSessionForANonDeployableNode(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct();
        $node = Node::factory()->create(['deployable' => false]);

        $this->actingAs($user)->postJson('/api/client/billing/checkout/create', [
            'payment_method' => 'stripe',
            'product_id' => $product->id,
            'node_id' => $node->id,
        ])->assertStatus(400);

        $this->assertSame(0, Order::count());
    }

    public function testCheckoutIsRejectedWhenThePaymentMethodIsNotOffered(): void
    {
        $user = User::factory()->create();
        $product = $this->makeProduct();
        $node = Node::factory()->create(['deployable' => true]);

        $this->actingAs($user)->postJson('/api/client/billing/checkout/create', [
            'payment_method' => 'not-a-channel',
            'product_id' => $product->id,
            'node_id' => $node->id,
        ])->assertStatus(400);

        $this->assertSame(0, Order::count());
    }
}
