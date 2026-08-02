<?php
namespace Jankx\Extensions\PaymentSystem\Gateways;

class GatewayManager
{
    protected static $instance;

    protected $gateways = [];

    protected $configs = [];

    public static function getInstance(): self
    {
        if (is_null(static::$instance)) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    public function register(string $name, string $gatewayClass): void
    {
        if (!is_subclass_of($gatewayClass, GatewayInterface::class)) {
            return;
        }
        $this->gateways[$name] = $gatewayClass;
    }

    public function get(string $name): ?GatewayInterface
    {
        if (!isset($this->gateways[$name])) {
            return null;
        }
        $class = $this->gateways[$name];
        $gateway = new $class();
        $config = $this->getConfig($name);
        if (!empty($config)) {
            $gateway->initialize($config);
        }
        return $gateway;
    }

    public function getAll(): array
    {
        return $this->gateways;
    }

    public function getAvailable(): array
    {
        $available = [];
        foreach ($this->gateways as $name => $class) {
            $gateway = $this->get($name);
            if ($gateway && $gateway->isAvailable()) {
                $available[$name] = $gateway;
            }
        }
        return $available;
    }

    public function setConfig(string $name, array $config): void
    {
        $this->configs[$name] = $config;
    }

    public function getConfig(string $name): array
    {
        $defaults = $this->getDefaultConfig($name);
        $saved = get_option("jankx_payment_gateway_{$name}", []);
        $merged = array_merge($defaults, $saved);

        // Determine testMode from saved config
        $isSandbox = !empty($merged['testMode']) && $merged['testMode'] === '1';
        $merged['testMode'] = $isSandbox;

        // Merge credentials based on mode
        if ($isSandbox) {
            if (!empty($merged['sandbox_api_key'])) {
                $merged['apiKey'] = $merged['sandbox_api_key'];
            }
            if (!empty($merged['sandbox_api_secret'])) {
                $merged['apiSecret'] = $merged['sandbox_api_secret'];
            }
        } else {
            if (!empty($merged['production_api_key'])) {
                $merged['apiKey'] = $merged['production_api_key'];
            }
            if (!empty($merged['production_api_secret'])) {
                $merged['apiSecret'] = $merged['production_api_secret'];
            }
        }

        return $merged;
    }

    public function getDefaultConfig(string $name): array
    {
        $defaults = [
            'testMode' => true,
        ];
        return apply_filters("jankx/payment/gateway/{$name}/default_config", $defaults);
    }

    public function saveConfig(string $name, array $config): bool
    {
        return update_option("jankx_payment_gateway_{$name}", $config);
    }

    public function getGatewayNames(): array
    {
        return array_keys($this->gateways);
    }

    public function hasGateway(string $name): bool
    {
        return isset($this->gateways[$name]);
    }

    /**
     * Check if a gateway is in sandbox/test mode
     */
    public function isSandboxMode(string $name): bool
    {
        $config = $this->getConfig($name);
        return !empty($config['testMode']);
    }

    /**
     * Get all gateways with their sandbox status
     */
    public function getGatewayModes(): array
    {
        $modes = [];
        foreach ($this->gateways as $name => $class) {
            $modes[$name] = [
                'is_sandbox' => $this->isSandboxMode($name),
                'label' => $this->isSandboxMode($name)
                    ? __('Sandbox', 'jankx')
                    : __('Production', 'jankx'),
            ];
        }
        return $modes;
    }
}
