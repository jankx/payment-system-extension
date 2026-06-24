<?php
namespace Jankx\Extensions\PaymentSystem\Tests\Models;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Jankx\Extensions\PaymentSystem\Models\Transaction;

class TransactionTest extends TestCase
{
    private $storedMeta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->storedMeta = [];

        Functions\when('wp_insert_post')->alias(function ($data) {
            if (isset($data['meta_input'])) {
                foreach ($data['meta_input'] as $key => $value) {
                    $this->storedMeta[$key] = $value;
                }
            }
            return 42;
        });

        Functions\when('get_post')->alias(function ($id) {
            if ($id == 42) {
                return new \WP_Post([
                    'ID' => 42,
                    'post_type' => 'jankx_transaction',
                    'post_title' => 'Test Payment',
                    'post_status' => 'publish',
                    'post_date' => '2025-01-15 10:30:00',
                ]);
            }
            return null;
        });

        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($id != 42) {
                return $single ? '' : [];
            }
            $value = $this->storedMeta[$key] ?? '';
            return $single ? $value : [$value];
        });

        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            if ($id == 42) {
                $this->storedMeta[$key] = $value;
            }
            return true;
        });

        Functions\when('do_action')->justReturn();
        Functions\when('__')->alias(function ($text, $domain = 'default') {
            return $text;
        });
        Functions\when('wp_error')->alias(function () {
            return false;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_create_transaction()
    {
        $transaction = Transaction::create([
            'title' => 'Payment 123',
            'gateway' => 'paypal',
            'amount' => 100.50,
            'currency' => 'USD',
            'order_id' => 99,
            'customer_email' => 'test@example.com',
            'customer_name' => 'John Doe',
        ]);

        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertEquals(42, $transaction->getId());
        $this->assertEquals('paypal', $transaction->getGateway());
        $this->assertEquals(100.50, $transaction->getAmount());
        $this->assertEquals('USD', $transaction->getCurrency());
        $this->assertEquals(99, $transaction->getOrderId());
        $this->assertEquals('test@example.com', $transaction->getCustomerEmail());
    }

    public function test_create_sets_default_values()
    {
        $transaction = Transaction::create([
            'gateway' => 'stripe',
            'amount' => 50,
        ]);

        $this->assertEquals('VND', $transaction->getCurrency());
        $this->assertEquals('pending', $transaction->getStatus());
        $this->assertEquals(0, $transaction->getTrackingCount());
    }

    public function test_set_and_get_transaction_id()
    {
        $transaction = Transaction::create([
            'gateway' => 'stripe',
            'amount' => 25,
        ]);

        $transaction->setTransactionId('txn_abc123');

        $this->assertEquals('txn_abc123', $this->storedMeta['_transaction_id']);
    }

    public function test_status_lifecycle()
    {
        $transaction = Transaction::create([
            'gateway' => 'paypal',
            'amount' => 100,
        ]);

        $this->assertEquals('pending', $transaction->getStatus());

        $transaction->setStatus(Transaction::STATUS_PROCESSING);
        $this->assertEquals('processing', $this->storedMeta['_status']);

        $transaction->setStatus(Transaction::STATUS_COMPLETED);
        $this->assertEquals('completed', $this->storedMeta['_status']);

        $transaction->setStatus(Transaction::STATUS_FAILED);
        $this->assertEquals('failed', $this->storedMeta['_status']);
    }

    public function test_raw_response()
    {
        $transaction = Transaction::create([
            'gateway' => 'stripe',
            'amount' => 200,
        ]);

        $transaction->setRawResponse('{"id":"ch_123","status":"succeeded"}');
        $this->assertEquals('{"id":"ch_123","status":"succeeded"}', $this->storedMeta['_raw_response']);
    }

    public function test_increment_tracking_count()
    {
        $transaction = Transaction::create([
            'gateway' => 'paypal',
            'amount' => 50,
        ]);

        $this->assertEquals(0, $transaction->getTrackingCount());

        $transaction->incrementTrackingCount();
        $this->assertEquals(1, $this->storedMeta['_tracking_count']);

        $transaction->incrementTrackingCount();
        $this->assertEquals(2, $this->storedMeta['_tracking_count']);
    }

    public function test_toArray()
    {
        $transaction = Transaction::create([
            'gateway' => 'stripe',
            'amount' => 75,
            'currency' => 'USD',
        ]);

        // Override with expected post-create values
        $this->storedMeta['_status'] = 'completed';
        $this->storedMeta['_transaction_id'] = 'txn_xyz';
        $this->storedMeta['_order_id'] = 10;
        $this->storedMeta['_customer_email'] = 'a@b.com';
        $this->storedMeta['_tracking_count'] = 3;

        $array = $transaction->toArray();

        $this->assertEquals(42, $array['id']);
        $this->assertEquals('stripe', $array['gateway']);
        $this->assertEquals(75, $array['amount']);
        $this->assertEquals('USD', $array['currency']);
        $this->assertEquals('completed', $array['status']);
        $this->assertEquals('txn_xyz', $array['transaction_id']);
        $this->assertEquals(10, $array['order_id']);
        $this->assertEquals('a@b.com', $array['customer_email']);
        $this->assertEquals('2025-01-15 10:30:00', $array['created_at']);
        $this->assertEquals(3, $array['tracking_count']);
    }

    public function test_constructor_with_numeric_id()
    {
        Functions\when('get_post')->alias(function ($id) {
            if ($id == 99) {
                return new \WP_Post([
                    'ID' => 99,
                    'post_type' => 'jankx_transaction',
                    'post_title' => 'Existing Payment',
                    'post_status' => 'publish',
                    'post_date' => '2025-03-01 08:00:00',
                ]);
            }
            return null;
        });

        $transaction = new Transaction(99);
        $this->assertEquals(99, $transaction->getId());
    }

    public function test_constructor_with_null_returns_empty_transaction()
    {
        $transaction = new Transaction(null);
        $this->assertEquals(0, $transaction->getId());
        $this->assertEquals([], $transaction->toArray());
    }
}
