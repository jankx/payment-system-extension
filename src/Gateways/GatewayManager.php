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
        return array_merge($defaults, $saved);
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
}
