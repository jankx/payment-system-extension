<?php
namespace Jankx\Extensions\PaymentSystem\Models;

use WP_Post;
use WP_Query;

class Transaction
{
    const POST_TYPE = 'jankx_transaction';

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_CANCELLED = 'cancelled';

    protected $post;

    public function __construct($post = null)
    {
        if ($post instanceof WP_Post) {
            $this->post = $post;
        } elseif (is_numeric($post)) {
            $this->post = get_post($post);
        }
    }

    public static function registerPostType(): void
    {
        if (post_type_exists(self::POST_TYPE)) {
            return;
        }

        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Transactions', 'jankx'),
                'singular_name' => __('Transaction', 'jankx'),
                'menu_name' => __('Payments', 'jankx'),
                'all_items' => __('All Transactions', 'jankx'),
                'edit_item' => __('Edit Transaction', 'jankx'),
                'view_item' => __('View Transaction', 'jankx'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => ['title', 'comments'],
            'capability_type' => 'shop_order',
            'map_meta_cap' => true,
        ]);
    }

    public static function create(array $data): self
    {
        $postId = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_title' => $data['title'] ?? sprintf(__('Payment %s', 'jankx'), uniqid()),
            'post_status' => 'publish',
            'meta_input' => [
                '_gateway' => $data['gateway'] ?? '',
                '_amount' => $data['amount'] ?? 0,
                '_currency' => $data['currency'] ?? 'VND',
                '_status' => $data['status'] ?? self::STATUS_PENDING,
                '_transaction_id' => $data['transaction_id'] ?? '',
                '_order_id' => $data['order_id'] ?? 0,
                '_customer_email' => $data['customer_email'] ?? '',
                '_customer_name' => $data['customer_name'] ?? '',
                '_raw_request' => $data['raw_request'] ?? '',
                '_raw_response' => '',
                '_tracking_count' => 0,
            ],
        ]);

        if (is_wp_error($postId)) {
            throw new \RuntimeException($postId->get_error_message());
        }

        return new self($postId);
    }

    public function getId(): int
    {
        return $this->post ? $this->post->ID : 0;
    }

    public function getGateway(): string
    {
        return get_post_meta($this->getId(), '_gateway', true);
    }

    public function getAmount(): float
    {
        return (float) get_post_meta($this->getId(), '_amount', true);
    }

    public function getCurrency(): string
    {
        return get_post_meta($this->getId(), '_currency', true);
    }

    public function getStatus(): string
    {
        return get_post_meta($this->getId(), '_status', true);
    }

    public function setStatus(string $status): void
    {
        update_post_meta($this->getId(), '_status', $status);
        do_action('jankx/payment/transaction_status_changed', $this, $status);
    }

    public function getTransactionId(): string
    {
        return get_post_meta($this->getId(), '_transaction_id', true);
    }

    public function setTransactionId(string $transactionId): void
    {
        update_post_meta($this->getId(), '_transaction_id', $transactionId);
    }

    public function getOrderId(): int
    {
        return (int) get_post_meta($this->getId(), '_order_id', true);
    }

    public function getCustomerEmail(): string
    {
        return get_post_meta($this->getId(), '_customer_email', true);
    }

    public function setRawResponse(string $response): void
    {
        update_post_meta($this->getId(), '_raw_response', $response);
    }

    public function getRawResponse(): string
    {
        return get_post_meta($this->getId(), '_raw_response', true);
    }

    public function incrementTrackingCount(): void
    {
        $count = (int) get_post_meta($this->getId(), '_tracking_count', true);
        update_post_meta($this->getId(), '_tracking_count', $count + 1);
    }

    public function getTrackingCount(): int
    {
        return (int) get_post_meta($this->getId(), '_tracking_count', true);
    }

    public function getCreatedAt(): string
    {
        return $this->post ? $this->post->post_date : '';
    }

    public static function find(string $transactionId, string $gateway = ''): ?self
    {
        $metaQuery = [
            'key' => '_transaction_id',
            'value' => $transactionId,
        ];

        if ($gateway) {
            $metaQuery = [
                'relation' => 'AND',
                $metaQuery,
                ['key' => '_gateway', 'value' => $gateway],
            ];
        }

        $query = new WP_Query([
            'post_type' => self::POST_TYPE,
            'posts_per_page' => 1,
            'meta_query' => [$metaQuery],
        ]);

        if ($query->have_posts()) {
            return new self($query->posts[0]);
        }

        return null;
    }

    public static function findPending(int $limit = 20): array
    {
        $query = new WP_Query([
            'post_type' => self::POST_TYPE,
            'posts_per_page' => $limit,
            'meta_query' => [
                [
                    'key' => '_status',
                    'value' => self::STATUS_PENDING,
                ],
            ],
        ]);

        return array_map(function ($post) {
            return new self($post);
        }, $query->posts);
    }

    public function toArray(): array
    {
        if (!$this->post) {
            return [];
        }
        return [
            'id' => $this->getId(),
            'gateway' => $this->getGateway(),
            'amount' => $this->getAmount(),
            'currency' => $this->getCurrency(),
            'status' => $this->getStatus(),
            'transaction_id' => $this->getTransactionId(),
            'order_id' => $this->getOrderId(),
            'customer_email' => $this->getCustomerEmail(),
            'created_at' => $this->getCreatedAt(),
            'tracking_count' => $this->getTrackingCount(),
        ];
    }
}
