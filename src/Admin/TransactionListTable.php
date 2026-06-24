<?php
namespace Jankx\Extensions\PaymentSystem\Admin;

use WP_List_Table;
use Jankx\Extensions\PaymentSystem\Models\Transaction;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class TransactionListTable extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'transaction',
            'plural' => 'transactions',
            'ajax' => false,
        ]);
    }

    public static function renderPage(): void
    {
        $table = new self();
        $table->prepare_items();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Transactions', 'jankx'); ?></h1>
            <form method="get">
                <input type="hidden" name="page" value="jankx-payment-transactions">
                <?php $table->search_box(__('Search', 'jankx'), 'transaction'); ?>
                <?php $table->display(); ?>
            </form>
        </div>
        <?php
    }

    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox" />',
            'id' => __('ID', 'jankx'),
            'gateway' => __('Gateway', 'jankx'),
            'transaction_id' => __('Transaction ID', 'jankx'),
            'amount' => __('Amount', 'jankx'),
            'status' => __('Status', 'jankx'),
            'customer_email' => __('Customer', 'jankx'),
            'tracking_count' => __('Tracking', 'jankx'),
            'date' => __('Date', 'jankx'),
        ];
    }

    public function prepare_items(): void
    {
        $this->_column_headers = [$this->get_columns(), [], []];

        $perPage = 20;
        $currentPage = $this->get_pagenum();

        $args = [
            'post_type' => Transaction::POST_TYPE,
            'posts_per_page' => $perPage,
            'paged' => $currentPage,
        ];

        $search = $_GET['s'] ?? '';
        if ($search) {
            $args['meta_query'] = [
                'relation' => 'OR',
                ['key' => '_transaction_id', 'value' => $search, 'compare' => 'LIKE'],
                ['key' => '_customer_email', 'value' => $search, 'compare' => 'LIKE'],
                ['key' => '_customer_name', 'value' => $search, 'compare' => 'LIKE'],
            ];
        }

        $query = new \WP_Query($args);
        $this->items = array_map(function ($post) {
            return (new Transaction($post))->toArray();
        }, $query->posts);

        $this->set_pagination_args([
            'total_items' => $query->found_posts,
            'per_page' => $perPage,
        ]);
    }

    public function column_default($item, $column_name): string
    {
        return esc_html($item[$column_name] ?? '');
    }

    public function column_cb($item): string
    {
        return sprintf('<input type="checkbox" name="id[]" value="%d" />', $item['id']);
    }

    public function column_status($item): string
    {
        $statuses = [
            Transaction::STATUS_PENDING => 'pending',
            Transaction::STATUS_PROCESSING => 'processing',
            Transaction::STATUS_COMPLETED => 'completed',
            Transaction::STATUS_FAILED => 'failed',
            Transaction::STATUS_REFUNDED => 'refunded',
            Transaction::STATUS_CANCELLED => 'cancelled',
        ];
        $class = $statuses[$item['status']] ?? 'unknown';
        return sprintf('<span class="jankx-badge jankx-badge--%s">%s</span>', $class, esc_html($item['status']));
    }

    public function column_amount($item): string
    {
        $amount = number_format($item['amount'] ?? 0, 0, ',', '.');
        $currency = $item['currency'] ?? 'VND';
        return sprintf('%s %s', $amount, $currency);
    }

    public function column_date($item): string
    {
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item['created_at']));
    }

    public function get_sortable_columns(): array
    {
        return [
            'id' => ['id', true],
            'amount' => ['amount', false],
            'date' => ['date', false],
        ];
    }
}
