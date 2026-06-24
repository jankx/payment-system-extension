<?php
namespace Jankx\Extensions\PaymentSystem\Tracking;

use Jankx\Extensions\PaymentSystem\Models\Transaction;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

class ApiTracker
{
    protected $gatewayManager;

    public function __construct()
    {
        $this->gatewayManager = GatewayManager::getInstance();
    }

    public function init(): void
    {
        add_action('jankx/payment/cron/track_transactions', [$this, 'trackPendingTransactions']);
        add_action('jankx/payment/cron/cleanup_stale', [$this, 'cleanupStaleTransactions']);

        if (!wp_next_scheduled('jankx/payment/cron/track_transactions')) {
            wp_schedule_event(time(), 'hourly', 'jankx/payment/cron/track_transactions');
        }
        if (!wp_next_scheduled('jankx/payment/cron/cleanup_stale')) {
            wp_schedule_event(time(), 'daily', 'jankx/payment/cron/cleanup_stale');
        }
    }

    public function trackPendingTransactions(): void
    {
        $transactions = Transaction::findPending(50);
        foreach ($transactions as $transaction) {
            $this->trackTransaction($transaction);
        }
    }

    public function trackTransaction(Transaction $transaction): void
    {
        $gatewayName = $transaction->getGateway();
        if (!$gatewayName) {
            return;
        }

        $gateway = $this->gatewayManager->get($gatewayName);
        if (!$gateway) {
            return;
        }

        $transactionId = $transaction->getTransactionId();
        if (!$transactionId) {
            return;
        }

        try {
            $status = $gateway->queryStatus($transactionId);
            if ($status !== $transaction->getStatus()) {
                $transaction->setStatus($status);
                $transaction->incrementTrackingCount();
                do_action('jankx/payment/transaction_tracked', $transaction, $status);
            }
        } catch (\Exception $e) {
            $transaction->incrementTrackingCount();
            error_log(sprintf(
                '[PaymentSystem] API tracking failed for transaction %s: %s',
                $transactionId,
                $e->getMessage()
            ));
        }
    }

    public function cleanupStaleTransactions(): void
    {
        $transactions = Transaction::findPending(100);
        $now = time();
        foreach ($transactions as $transaction) {
            $createdAt = strtotime($transaction->getCreatedAt());
            if ($createdAt && ($now - $createdAt) > DAY_IN_SECONDS * 7) {
                $transaction->setStatus(Transaction::STATUS_FAILED);
            }
        }
    }
}
