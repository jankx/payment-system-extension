<?php
namespace Jankx\Extensions\PaymentSystem;

use Jankx\Extensions\AbstractExtension;
use Jankx\Extensions\PaymentSystem\Admin\SettingsPage;
use Jankx\Extensions\PaymentSystem\Admin\TransactionListTable;
use Jankx\Extensions\PaymentSystem\Rest\PaymentController;
use Jankx\Extensions\PaymentSystem\Tracking\ApiTracker;
use Jankx\Extensions\PaymentSystem\Tracking\WebhookHandler;
use Jankx\Extensions\PaymentSystem\Imap\ImapMonitor;
use Jankx\Extensions\PaymentSystem\Models\Transaction;

class PaymentSystemExtension extends AbstractExtension
{
    public const TEXT_DOMAIN = 'jankx_payment';

    protected static $instance;

    public function __construct()
    {
        $this->register_autoloader();
        parent::__construct();
    }

    protected function register_autoloader()
    {
        spl_autoload_register(function ($class) {
            $prefix = 'Jankx\\Extensions\\PaymentSystem\\';
            $base_dir = __DIR__ . '/src/';

            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }

            $relative_class = substr($class, $len);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

            if (file_exists($file)) {
                require $file;
            }
        });
    }

    public function init(): void
    {
        self::$instance = $this;
    }

    public static function get_instance(): ?self
    {
        return self::$instance;
    }

    public function register_hooks(): void
    {
        // Load this extension's own translations.
        $this->load_textdomain();

        // CPT registration
        add_action('init', [$this, 'registerTransactionCpt']);

        // Cron schedules
        add_filter('cron_schedules', [$this, 'addCronSchedules']);
        add_action('jankx/payment/cron/track', [ApiTracker::class, 'run']);
        add_action('jankx/payment/cron/imap', [ImapMonitor::class, 'run']);

        // Admin
        if (is_admin()) {
            $settingsPage = new SettingsPage();
            $settingsPage->init();
        }

        // REST
        $paymentController = new PaymentController();
        $paymentController->init();

        // Webhook
        $webhookHandler = new WebhookHandler();
        $webhookHandler->init();

        // Schedule cron events on activation
        add_action('jankx/extension/activated', [$this, 'scheduleCronEvents']);

        // Gateway registration hook. Extensions are loaded by
        // ThemeExtensionManager at after_setup_theme priority 15, sorted
        // alphabetically when they share the same dependency level, so a
        // synchronous do_action() here would miss gateway extensions whose
        // directory sorts after this one (e.g. qrviet, zalopay). Defer the
        // dispatch until every extension has registered its callbacks.
        add_action('after_setup_theme', [$this, 'dispatchGatewayRegistration'], 20);
    }

    public function dispatchGatewayRegistration(): void
    {
        do_action('jankx/payment/register_gateways');
    }

    /**
     * Load the extension's own translations from the bundled languages
     * directory, following the same pattern as the other Jankx extensions.
     */
    protected function load_textdomain(): void
    {
        /** @var \WP_Textdomain_Registry $wp_textdomain_registry */
        global $wp_textdomain_registry;

        $locale = determine_locale();
        $dir    = __DIR__ . '/languages';

        if ($wp_textdomain_registry instanceof \WP_Textdomain_Registry) {
            $wp_textdomain_registry->set_custom_path(self::TEXT_DOMAIN, $dir);
        }

        $mo = $dir . '/' . self::TEXT_DOMAIN . '-' . $locale . '.mo';
        if (!is_readable($mo)) {
            return;
        }

        $loader = static function () use ($mo) {
            load_textdomain(self::TEXT_DOMAIN, $mo);
        };

        if (did_action('after_setup_theme')) {
            $loader();
        } else {
            add_action('after_setup_theme', $loader, 5);
        }
    }

    public function registerTransactionCpt(): void
    {
        register_post_type(Transaction::POST_TYPE, [
            'labels' => [
                'name' => __('Transactions', 'jankx_payment'),
                'singular_name' => __('Transaction', 'jankx_payment'),
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'publicly_queryable' => false,
            'capability_type' => 'post',
            'capabilities' => [
                'create_posts' => 'do_not_allow',
            ],
            'map_meta_cap' => true,
            'supports' => ['title', 'custom-fields'],
        ]);
    }

    public function addCronSchedules(array $schedules): array
    {
        $schedules['jankx_payment_hourly'] = [
            'interval' => HOUR_IN_SECONDS,
            'display' => __('Payment Tracker (Hourly)', 'jankx_payment'),
        ];
        $schedules['jankx_payment_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('IMAP Monitor (5 Minutes)', 'jankx_payment'),
        ];
        return $schedules;
    }

    public function scheduleCronEvents(): void
    {
        if (!wp_next_scheduled('jankx/payment/cron/track')) {
            wp_schedule_event(time(), 'jankx_payment_hourly', 'jankx/payment/cron/track');
        }
        if (!wp_next_scheduled('jankx/payment/cron/imap')) {
            wp_schedule_event(time(), 'jankx_payment_five_minutes', 'jankx/payment/cron/imap');
        }
    }

    public function uninstall(): bool
    {
        wp_clear_scheduled_hook('jankx/payment/cron/track');
        wp_clear_scheduled_hook('jankx/payment/cron/imap');
        return parent::uninstall();
    }
}
