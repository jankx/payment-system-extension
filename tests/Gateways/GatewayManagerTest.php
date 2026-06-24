<?php
namespace Jankx\Extensions\PaymentSystem\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayInterface;

class GatewayManagerTest extends TestCase
{
    private $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Reset singleton state via reflection
        $ref = new \ReflectionProperty(GatewayManager::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
        Functions\when('apply_filters')->alias(function ($filter, $value) {
            return $value;
        });

        $this->manager = GatewayManager::getInstance();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_singleton_pattern()
    {
        $instance1 = GatewayManager::getInstance();
        $instance2 = GatewayManager::getInstance();

        $this->assertSame($instance1, $instance2);
    }

    public function test_register_gateway()
    {
        $this->manager->register('test_gateway', TestGatewayStub::class);

        $this->assertTrue($this->manager->hasGateway('test_gateway'));
    }

    public function test_register_invalid_gateway()
    {
        $this->manager->register('invalid', \stdClass::class);

        $this->assertFalse($this->manager->hasGateway('invalid'));
    }

    public function test_get_gateway()
    {
        $this->manager->register('stub_gateway', TestGatewayStub::class);
        $gateway = $this->manager->get('stub_gateway');

        $this->assertInstanceOf(GatewayInterface::class, $gateway);
        $this->assertEquals('stub_gateway', $gateway->getName());
    }

    public function test_get_nonexistent_gateway()
    {
        $this->assertNull($this->manager->get('nonexistent'));
    }

    public function test_get_gateway_names()
    {
        $this->manager->register('gateway_a', TestGatewayStub::class);
        $this->manager->register('gateway_b', TestGatewayStub::class);

        $names = $this->manager->getGatewayNames();
        $this->assertContains('gateway_a', $names);
        $this->assertContains('gateway_b', $names);
    }

    public function test_getAll_returns_all_registered()
    {
        $this->manager->register('all_a', TestGatewayStub::class);
        $this->manager->register('all_b', TestGatewayStub::class);

        $all = $this->manager->getAll();
        $this->assertCount(2, $all);
    }

    public function test_getAvailable_returns_only_available_gateways()
    {
        $this->manager->register('available_gw', TestGatewayStub::class);
        $this->manager->register('unavailable_gw', UnavailableGatewayStub::class);

        $available = $this->manager->getAvailable();
        $this->assertArrayHasKey('available_gw', $available);
        $this->assertArrayNotHasKey('unavailable_gw', $available);
    }

    public function test_setConfig_and_getConfig()
    {
        $this->manager->register('config_gw', TestGatewayStub::class);

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'jankx_payment_gateway_config_gw') {
                return ['api_key' => 'test-key-123'];
            }
            return $default;
        });

        $config = $this->manager->getConfig('config_gw');
        $this->assertEquals('test-key-123', $config['api_key']);
    }

    public function test_getConfig_merges_with_defaults()
    {
        $this->manager->register('merge_gw', TestGatewayStub::class);

        $config = $this->manager->getConfig('merge_gw');
        $this->assertArrayHasKey('testMode', $config);
        $this->assertTrue($config['testMode']);
    }

    public function test_saveConfig()
    {
        $this->manager->register('save_gw', TestGatewayStub::class);

        $savedConfig = null;
        Functions\when('update_option')->alias(function ($key, $value) use (&$savedConfig) {
            if ($key === 'jankx_payment_gateway_save_gw') {
                $savedConfig = $value;
            }
            return true;
        });

        $this->manager->saveConfig('save_gw', ['api_key' => 'saved-key']);
        $this->assertEquals('saved-key', $savedConfig['api_key']);
    }

    public function test_getGateway_initializes_with_config()
    {
        $this->manager->register('init_gw', TestGatewayStub::class);

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === 'jankx_payment_gateway_init_gw') {
                return ['testMode' => false];
            }
            return $default;
        });

        $gateway = $this->manager->get('init_gw');
        $this->assertFalse($gateway->testMode);
    }
}

class TestGatewayStub implements GatewayInterface
{
    public $testMode = true;

    public function getName(): string
    {
        return 'stub_gateway';
    }

    public function initialize(array $parameters): void
    {
        if (isset($parameters['testMode'])) {
            $this->testMode = $parameters['testMode'];
        }
    }

    public function purchase(array $parameters): array
    {
        return ['status' => 'success'];
    }

    public function completePurchase(array $parameters): array
    {
        return ['status' => 'success'];
    }

    public function refund(array $parameters): array
    {
        return ['status' => 'success'];
    }

    public function queryStatus(string $transactionId): string
    {
        return 'completed';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getSettingsFields(): array
    {
        return [
            ['name' => 'api_key', 'label' => 'API Key'],
        ];
    }
}

class UnavailableGatewayStub implements GatewayInterface
{
    public function getName(): string
    {
        return 'unavailable';
    }

    public function initialize(array $parameters): void
    {
    }

    public function purchase(array $parameters): array
    {
        return ['status' => 'failed'];
    }

    public function completePurchase(array $parameters): array
    {
        return ['status' => 'failed'];
    }

    public function refund(array $parameters): array
    {
        return ['status' => 'failed'];
    }

    public function queryStatus(string $transactionId): string
    {
        return 'unknown';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getSettingsFields(): array
    {
        return [];
    }
}
