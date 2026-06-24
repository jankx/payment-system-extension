<?php
namespace Jankx\Extensions\PaymentSystem\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Jankx\Extensions\PaymentSystem\Gateways\OmnipayGateway;

class OmnipayGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_getName_returns_gateway_name()
    {
        $gateway = new OmnipayGateway('PayPal_Express', 'PayPal Express');
        $this->assertEquals('PayPal_Express', $gateway->getName());
    }

    public function test_isAvailable_returns_false_before_initialize()
    {
        $gateway = new OmnipayGateway('Stripe', 'Stripe');
        $this->assertFalse($gateway->isAvailable());
    }

    public function test_initialize_creates_omnipay_gateway()
    {
        $gateway = new OmnipayGateway('PayPal_Express', 'PayPal');

        Functions\when('Omnipay\Common\GatewayFactory')->alias(function () {
            return new \Omnipay\Common\GatewayFactory();
        });

        $gateway->initialize([
            'username' => 'test@paypal.com',
            'password' => 'secret',
            'signature' => 'sig-123',
            'testMode' => true,
        ]);

        $this->assertTrue($gateway->isAvailable());
    }

    public function test_purchase_throws_exception_without_initialize()
    {
        $this->expectException(\Error::class);

        $gateway = new OmnipayGateway('Stripe', 'Stripe');
        $gateway->purchase(['amount' => 10.00]);
    }

    public function test_completePurchase_throws_exception_without_initialize()
    {
        $this->expectException(\Error::class);

        $gateway = new OmnipayGateway('Stripe', 'Stripe');
        $gateway->completePurchase([]);
    }

    public function test_refund_throws_exception_without_initialize()
    {
        $this->expectException(\Error::class);

        $gateway = new OmnipayGateway('Stripe', 'Stripe');
        $gateway->refund([]);
    }

    public function test_queryStatus_returns_unknown_without_support()
    {
        $gateway = new OmnipayGateway('Dummy', 'Dummy');
        $this->assertEquals('unknown', $gateway->queryStatus('txn_123'));
    }

    public function test_getName_without_display_name()
    {
        $gateway = new OmnipayGateway('Stripe');
        $this->assertEquals('Stripe', $gateway->getName());
    }

    public function test_getSettingsFields_returns_empty_array()
    {
        $gateway = new OmnipayGateway('PayPal_Express', 'PayPal');
        $this->assertIsArray($gateway->getSettingsFields());
        $this->assertCount(0, $gateway->getSettingsFields());
    }
}
