<?php
namespace Jankx\Extensions\PaymentSystem\Admin;

use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

class SettingsPage
{
    protected $gatewayManager;

    const PAGE_SLUG = 'jankx-payment-settings';

    public function __construct()
    {
        $this->gatewayManager = GatewayManager::getInstance();
    }

    public function init(): void
    {
        add_action('admin_menu', [$this, 'addAdminMenu'], 20);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addAdminMenu(): void
    {
        add_submenu_page(
            'jankx-theme-options',
            __('Payment Settings', 'jankx'),
            __('Payments', 'jankx'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __('Transactions', 'jankx'),
            __('Transactions', 'jankx'),
            'manage_options',
            'jankx-payment-transactions',
            [TransactionListTable::class, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        // General settings
        register_setting('jankx_payment', 'jankx_payment_currency', [
            'default' => 'VND',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('jankx_payment', 'jankx_payment_default_gateway', [
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        // IMAP settings
        register_setting('jankx_payment', 'jankx_payment_imap_host');
        register_setting('jankx_payment', 'jankx_payment_imap_port', ['default' => 993]);
        register_setting('jankx_payment', 'jankx_payment_imap_username');
        register_setting('jankx_payment', 'jankx_payment_imap_password');
        register_setting('jankx_payment', 'jankx_payment_imap_ssl', ['default' => true]);
        register_setting('jankx_payment', 'jankx_payment_imap_mailbox', ['default' => 'INBOX']);
        register_setting('jankx_payment', 'jankx_payment_imap_since_days', ['default' => 7]);
    }

    public function renderPage(): void
    {
        $activeTab = $_GET['tab'] ?? 'general';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Payment Settings', 'jankx'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&tab=general"
                   class="nav-tab <?php echo $activeTab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('General', 'jankx'); ?>
                </a>
                <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&tab=gateways"
                   class="nav-tab <?php echo $activeTab === 'gateways' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Gateways', 'jankx'); ?>
                </a>
                <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>&tab=imap"
                   class="nav-tab <?php echo $activeTab === 'imap' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('IMAP Monitor', 'jankx'); ?>
                </a>
            </nav>

            <form method="post" action="options.php">
                <?php
                switch ($activeTab) {
                    case 'gateways':
                        $this->renderGatewaySettings();
                        break;
                    case 'imap':
                        $this->renderImapSettings();
                        break;
                    default:
                        $this->renderGeneralSettings();
                }
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    protected function renderGeneralSettings(): void
    {
        settings_fields('jankx_payment');
        $currency = get_option('jankx_payment_currency', 'VND');
        $defaultGateway = get_option('jankx_payment_default_gateway', '');
        ?>
        <table class="form-table">
            <tr>
                <th><label for="jankx_payment_currency"><?php esc_html_e('Currency', 'jankx'); ?></label></th>
                <td>
                    <select id="jankx_payment_currency" name="jankx_payment_currency">
                        <?php foreach (['VND', 'USD', 'EUR'] as $c): ?>
                            <option value="<?php echo esc_attr($c); ?>" <?php selected($currency, $c); ?>>
                                <?php echo esc_html($c); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="jankx_payment_default_gateway"><?php esc_html_e('Default Gateway', 'jankx'); ?></label></th>
                <td>
                    <select id="jankx_payment_default_gateway" name="jankx_payment_default_gateway">
                        <option value=""><?php esc_html_e('-- Select --', 'jankx'); ?></option>
                        <?php foreach ($this->gatewayManager->getGatewayNames() as $name): ?>
                            <option value="<?php echo esc_attr($name); ?>" <?php selected($defaultGateway, $name); ?>>
                                <?php echo esc_html($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    protected function renderGatewaySettings(): void
    {
        do_action('jankx/payment/admin/gateway_settings_before');

        foreach ($this->gatewayManager->getGatewayNames() as $name) {
            $gateway = $this->gatewayManager->get($name);
            if (!$gateway) {
                continue;
            }
            $config = $this->gatewayManager->getConfig($name);
            ?>
            <h2><?php echo esc_html($gateway->getName()); ?></h2>
            <table class="form-table">
                <?php foreach ($gateway->getSettingsFields() as $field): ?>
                    <tr>
                        <th><label><?php echo esc_html($field['label'] ?? ''); ?></label></th>
                        <td>
                            <input type="text"
                                   name="jankx_payment_gateway_<?php echo esc_attr($name); ?>[<?php echo esc_attr($field['name']); ?>]"
                                   value="<?php echo esc_attr($config[$field['name']] ?? ''); ?>"
                                   class="regular-text">
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php
        }

        do_action('jankx/payment/admin/gateway_settings_after');
    }

    protected function renderImapSettings(): void
    {
        ?>
        <table class="form-table">
            <tr>
                <th><label for="jankx_payment_imap_host"><?php esc_html_e('IMAP Host', 'jankx'); ?></label></th>
                <td>
                    <input type="text" id="jankx_payment_imap_host" name="jankx_payment_imap_host"
                           value="<?php echo esc_attr(get_option('jankx_payment_imap_host', '')); ?>"
                           class="regular-text" placeholder="imap.example.com">
                </td>
            </tr>
            <tr>
                <th><label for="jankx_payment_imap_port"><?php esc_html_e('Port', 'jankx'); ?></label></th>
                <td>
                    <input type="number" id="jankx_payment_imap_port" name="jankx_payment_imap_port"
                           value="<?php echo esc_attr(get_option('jankx_payment_imap_port', 993)); ?>"
                           class="small-text">
                </td>
            </tr>
            <tr>
                <th><label for="jankx_payment_imap_username"><?php esc_html_e('Username', 'jankx'); ?></label></th>
                <td>
                    <input type="text" id="jankx_payment_imap_username" name="jankx_payment_imap_username"
                           value="<?php echo esc_attr(get_option('jankx_payment_imap_username', '')); ?>"
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th><label for="jankx_payment_imap_password"><?php esc_html_e('Password', 'jankx'); ?></label></th>
                <td>
                    <input type="password" id="jankx_payment_imap_password" name="jankx_payment_imap_password"
                           value="<?php echo esc_attr(get_option('jankx_payment_imap_password', '')); ?>"
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th><label for="jankx_payment_imap_ssl"><?php esc_html_e('Use SSL', 'jankx'); ?></label></th>
                <td>
                    <input type="checkbox" id="jankx_payment_imap_ssl" name="jankx_payment_imap_ssl" value="1"
                        <?php checked(get_option('jankx_payment_imap_ssl', true)); ?>>
                </td>
            </tr>
            <tr>
                <th><label for="jankx_payment_imap_since_days"><?php esc_html_e('Lookback Days', 'jankx'); ?></label></th>
                <td>
                    <input type="number" id="jankx_payment_imap_since_days" name="jankx_payment_imap_since_days"
                           value="<?php echo esc_attr(get_option('jankx_payment_imap_since_days', 7)); ?>"
                           class="small-text" min="1" max="90">
                </td>
            </tr>
        </table>
        <?php
    }
}
