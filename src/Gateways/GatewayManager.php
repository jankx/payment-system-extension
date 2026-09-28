<?php
namespace Jankx\Extensions\PaymentSystem\Gateways;

class GatewayManager
{
    protected static $instance;

    protected $gateways = [];

    protected $configs = [];

    /**
     * Fields that contain sensitive credentials (will be encrypted).
     */
    protected static $sensitiveFields = [
        'sandbox_api_key',
        'sandbox_api_secret',
        'sandbox_secure_hash',
        'sandbox_password',
        'production_api_key',
        'production_api_secret',
        'production_secure_hash',
        'production_password',
        'api_key',
        'api_secret',
        'secret_key',
        'secure_hash',
    ];

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
        if ($gateway instanceof AbstractGateway) {
            $gateway->setSlug($name);
        }
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

        // Decrypt sensitive fields
        $saved = $this->decryptConfig($saved);

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
        // Encrypt sensitive fields before saving
        $config = $this->encryptConfig($config);
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

    // ── AES-256-CBC Encryption ──────────────────────────────────────

    /**
     * Check if a field name is sensitive
     */
    protected function isSensitiveField(string $key): bool
    {
        // Check exact match
        if (in_array($key, self::$sensitiveFields, true)) {
            return true;
        }

        // Check patterns: key, secret, hash, password, token
        $patterns = ['key', 'secret', 'hash', 'password', 'token', 'credential'];
        $lower = strtolower($key);
        foreach ($patterns as $pattern) {
            if (strpos($lower, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Encrypt sensitive fields in config array
     */
    protected function encryptConfig(array $config): array
    {
        foreach ($config as $key => &$value) {
            if ($this->isSensitiveField($key) && is_string($value) && $value !== '') {
                $value = $this->encrypt($value);
            }
        }
        unset($value);
        return $config;
    }

    /**
     * Decrypt sensitive fields in config array
     */
    protected function decryptConfig(array $config): array
    {
        foreach ($config as $key => &$value) {
            if ($this->isSensitiveField($key) && is_string($value) && $value !== '') {
                $decrypted = $this->decrypt($value);
                if ($decrypted !== false) {
                    $value = $decrypted;
                }
                // If decrypt fails, keep original (not encrypted or corrupted)
            }
        }
        unset($value);
        return $config;
    }

    /**
     * Encrypt a string using AES-256-CBC
     */
    protected function encrypt(string $data): string
    {
        $key = $this->getEncryptionKey();
        $cipher = 'aes-256-cbc';

        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $encrypted = openssl_encrypt($data, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            return $data;
        }

        // Prepend IV and HMAC for integrity
        $hmac = hash_hmac('sha256', $iv . $encrypted, $key, true);
        return base64_encode($hmac . $iv . $encrypted);
    }

    /**
     * Decrypt a string using AES-256-CBC
     */
    protected function decrypt(string $data): string|false
    {
        $key = $this->getEncryptionKey();
        $cipher = 'aes-256-cbc';

        $decoded = base64_decode($data, true);
        if ($decoded === false || strlen($decoded) < 45) {
            return false;
        }

        // Extract HMAC (32 bytes), IV, and ciphertext
        $hmac = substr($decoded, 0, 32);
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = substr($decoded, 32, $ivLength);
        $encrypted = substr($decoded, 32 + $ivLength);

        // Verify HMAC
        $expectedHmac = hash_hmac('sha256', $iv . $encrypted, $key, true);
        if (!hash_equals($expectedHmac, $hmac)) {
            return false;
        }

        $decrypted = openssl_decrypt($encrypted, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : false;
    }

    /**
     * Get encryption key from WordPress AUTH_KEY or generate fallback
     */
    protected function getEncryptionKey(): string
    {
        if (defined('AUTH_KEY') && AUTH_KEY) {
            return hash('sha256', AUTH_KEY, true);
        }

        // Fallback: use site URL + salt
        $salt = defined('LOGGED_SALT') ? LOGGED_SALT : wp_salt();
        return hash('sha256', site_url() . $salt, true);
    }
}
