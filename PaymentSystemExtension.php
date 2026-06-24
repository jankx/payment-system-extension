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

        // Gateway registration hook
        do_action('jankx/payment/register_gateways');
    }

    public function registerTransactionCpt(): void
    {
        register_post_type(Transaction::POST_TYPE, [
            'labels' => [
                'name' => __('Transactions', 'jankx'),
                'singular_name' => __('Transaction', 'jankx'),
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
            'display' => __('Payment Tracker (Hourly)', 'jankx'),
        ];
        $schedules['jankx_payment_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('IMAP Monitor (5 Minutes)', 'jankx'),
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
