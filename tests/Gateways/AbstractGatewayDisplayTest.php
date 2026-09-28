<?php
namespace Jankx\Extensions\PaymentSystem\Tests\Gateways;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Jankx\Extensions\PaymentSystem\Gateways\AbstractGateway;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

class DisplayGatewayStub extends AbstractGateway
{
    public $icon = '<svg>stub</svg>';
    public $text = 'Stub Gateway';

    protected function getDefaultIcon(): string
    {
        return $this->icon;
    }

    protected function getDefaultText(): string
    {
        return $this->text;
    }

    public function getName(): string
    {
        return $this->text;
    }

    public function initialize(array $parameters): void
    {
    }

    public function purchase(array $parameters): array
    {
        return [];
    }

    public function completePurchase(array $parameters): array
    {
        return [];
    }

    public function refund(array $parameters): array
    {
        return [];
    }

    public function queryStatus(string $transactionId): string
    {
        return 'unknown';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getSettingsFields(): array
    {
        return [];
    }
}

class AbstractGatewayDisplayTest extends TestCase
{
    private $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $GLOBALS['__filters'] = [];
        Functions\when('add_filter')->alias(function ($tag, $callback) {
            $GLOBALS['__filters'][$tag][] = $callback;
            return true;
        });
        Functions\when('apply_filters')->alias(function ($tag, $value, ...$args) {
            foreach ($GLOBALS['__filters'][$tag] ?? [] as $callback) {
                $value = $callback($value, ...$args);
            }
            return $value;
        });
        Functions\when('get_option')->justReturn([]);

        $this->gateway = new DisplayGatewayStub();
        $this->gateway->setSlug('stub');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__filters']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_default_display_type_is_icon()
    {
        $this->assertSame(AbstractGateway::SHOW_ICON, $this->gateway->getDisplayType());
    }

    public function test_display_type_can_be_filtered_per_gateway()
    {
        add_filter('jankx/payment/gateway/stub/display_type', function () {
            return AbstractGateway::SHOW_ICON_TEXT;
        });

        $this->assertSame(AbstractGateway::SHOW_ICON_TEXT, $this->gateway->getDisplayType());
    }

    public function test_invalid_display_type_falls_back_to_icon()
    {
        add_filter('jankx/payment/gateway/stub/display_type', function () {
            return 'banana';
        });

        $this->assertSame(AbstractGateway::SHOW_ICON, $this->gateway->getDisplayType());
    }

    public function test_icon_position_defaults_to_left()
    {
        $this->assertSame(AbstractGateway::ICON_LEFT, $this->gateway->getIconPosition());
    }

    public function test_icon_position_can_be_filtered()
    {
        add_filter('jankx/payment/gateway/stub/icon_position', function () {
            return AbstractGateway::ICON_RIGHT;
        });

        $this->assertSame(AbstractGateway::ICON_RIGHT, $this->gateway->getIconPosition());
    }

    public function test_icon_can_be_filtered_per_gateway()
    {
        add_filter('jankx/payment/gateway/stub/icon', function () {
            return '<svg>filtered</svg>';
        });

        $this->assertSame('<svg>filtered</svg>', $this->gateway->getIcon());
    }

    public function test_text_can_be_filtered_per_gateway()
    {
        add_filter('jankx/payment/gateway/stub/text', function () {
            return 'Filtered label';
        });

        $this->assertSame('Filtered label', $this->gateway->getText());
    }

    public function test_display_returns_icon_type_with_icon_and_text()
    {
        $display = $this->gateway->getDisplay();

        $this->assertSame(AbstractGateway::SHOW_ICON, $display['type']);
        $this->assertSame('<svg>stub</svg>', $display['icon']);
        $this->assertSame('Stub Gateway', $display['text']);
        $this->assertSame(AbstractGateway::ICON_LEFT, $display['icon_position']);
    }

    public function test_display_falls_back_to_text_when_icon_is_empty()
    {
        $this->gateway->icon = '';

        $display = $this->gateway->getDisplay();

        $this->assertSame(AbstractGateway::SHOW_TEXT, $display['type']);
        $this->assertSame('', $display['icon']);
        $this->assertSame('Stub Gateway', $display['text']);
    }

    public function test_display_falls_back_to_text_when_icon_filter_empties_icon()
    {
        add_filter('jankx/payment/gateway/stub/icon', function () {
            return '   ';
        });

        $display = $this->gateway->getDisplay();

        $this->assertSame(AbstractGateway::SHOW_TEXT, $display['type']);
        $this->assertSame('', $display['icon']);
    }

    public function test_display_icon_text_type_keeps_both_parts()
    {
        add_filter('jankx/payment/gateway/stub/display_type', function () {
            return AbstractGateway::SHOW_ICON_TEXT;
        });
        add_filter('jankx/payment/gateway/stub/icon_position', function () {
            return AbstractGateway::ICON_RIGHT;
        });

        $display = $this->gateway->getDisplay();

        $this->assertSame(AbstractGateway::SHOW_ICON_TEXT, $display['type']);
        $this->assertSame('<svg>stub</svg>', $display['icon']);
        $this->assertSame('Stub Gateway', $display['text']);
        $this->assertSame(AbstractGateway::ICON_RIGHT, $display['icon_position']);
    }

    public function test_display_type_text_skips_icon()
    {
        add_filter('jankx/payment/gateway/stub/display_type', function () {
            return AbstractGateway::SHOW_TEXT;
        });

        $display = $this->gateway->getDisplay();

        $this->assertSame(AbstractGateway::SHOW_TEXT, $display['type']);
        $this->assertSame('', $display['icon']);
        $this->assertSame('Stub Gateway', $display['text']);
    }

    public function test_gateway_manager_sets_slug_when_getting_instance()
    {
        $ref = new \ReflectionProperty(GatewayManager::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $manager = GatewayManager::getInstance();
        $manager->register('stub_registered', DisplayGatewayStub::class);

        $gateway = $manager->get('stub_registered');

        $this->assertInstanceOf(DisplayGatewayStub::class, $gateway);
        $this->assertSame('stub_registered', $gateway->getSlug());

        // Filter tag now uses the manager-assigned slug.
        add_filter('jankx/payment/gateway/stub_registered/text', function () {
            return 'Manager slug label';
        });
        $this->assertSame('Manager slug label', $gateway->getText());
    }
}
