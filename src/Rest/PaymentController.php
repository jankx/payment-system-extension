<?php
namespace Jankx\Extensions\PaymentSystem\Rest;

use Jankx\Extensions\PaymentSystem\Models\Transaction;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

class PaymentController
{
    protected $gatewayManager;

    public function __construct()
    {
        $this->gatewayManager = GatewayManager::getInstance();
    }

    public function init(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('jankx/v1', '/payment/create', [
            'methods' => 'POST',
            'callback' => [$this, 'createPayment'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route('jankx/v1', '/payment/(?P<id>\d+)/status', [
            'methods' => 'GET',
            'callback' => [$this, 'getStatus'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route('jankx/v1', '/payment/(?P<id>\d+)/process', [
            'methods' => 'POST',
            'callback' => [$this, 'processReturn'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('jankx/v1', '/gateways', [
            'methods' => 'GET',
            'callback' => [$this, 'listGateways'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function checkPermission(): bool
    {
        return is_user_logged_in();
    }

    public function listGateways(): \WP_REST_Response
    {
        $gateways = [];
        foreach ($this->gatewayManager->getAvailable() as $name => $gateway) {
            $gateways[$name] = [
                'name' => $gateway->getName(),
            ];
        }
        return new \WP_REST_Response(['gateways' => $gateways]);
    }

    public function createPayment(\WP_REST_Request $request): \WP_REST_Response
    {
        $gatewayName = $request->get_param('gateway');
        $amount = $request->get_param('amount');
        $currency = $request->get_param('currency') ?: get_option('jankx_payment_currency', 'VND');
        $orderId = $request->get_param('order_id') ?: 0;
        $returnUrl = $request->get_param('return_url');
        $cancelUrl = $request->get_param('cancel_url');

        if (!$gatewayName || !$amount) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Gateway and amount are required', 'jankx'),
            ], 400);
        }

        $gateway = $this->gatewayManager->get($gatewayName);
        if (!$gateway) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => sprintf(__('Gateway %s not available', 'jankx'), $gatewayName),
            ], 400);
        }

        $currentUser = wp_get_current_user();

        $transaction = Transaction::create([
            'title' => sprintf(__('Order #%s', 'jankx'), $orderId ?: uniqid()),
            'gateway' => $gatewayName,
            'amount' => $amount,
            'currency' => $currency,
            'status' => Transaction::STATUS_PENDING,
            'order_id' => $orderId,
            'customer_email' => $currentUser->user_email,
            'customer_name' => $currentUser->display_name,
        ]);

        try {
            $result = $gateway->purchase([
                'amount' => $amount,
                'currency' => $currency,
                'transactionId' => $transaction->getId(),
                'returnUrl' => $returnUrl ?: rest_url("jankx/v1/payment/{$transaction->getId()}/process"),
                'cancelUrl' => $cancelUrl ?: home_url(),
                'description' => sprintf(__('Payment #%d', 'jankx'), $transaction->getId()),
            ]);

            if (!empty($result['transactionId'])) {
                $transaction->setTransactionId($result['transactionId']);
            }

            return new \WP_REST_Response([
                'success' => true,
                'transaction_id' => $transaction->getId(),
                'payment' => $result,
            ]);
        } catch (\Exception $e) {
            $transaction->setStatus(Transaction::STATUS_FAILED);
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getStatus(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $transaction = new Transaction($id);

        if (!$transaction->getId()) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Transaction not found', 'jankx'),
            ], 404);
        }

        return new \WP_REST_Response([
            'success' => true,
            'transaction' => $transaction->toArray(),
        ]);
    }

    public function processReturn(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $transaction = new Transaction($id);

        if (!$transaction->getId()) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Transaction not found', 'jankx'),
            ], 404);
        }

        $gateway = $this->gatewayManager->get($transaction->getGateway());
        if (!$gateway) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('Gateway not available', 'jankx'),
            ], 400);
        }

        try {
            $result = $gateway->completePurchase([
                'transactionId' => $transaction->getId(),
                'request_params' => $request->get_params(),
            ]);

            if ($result['status'] === 'success') {
                $transaction->setStatus(Transaction::STATUS_COMPLETED);
            } else {
                $transaction->setStatus(Transaction::STATUS_FAILED);
            }

            $transaction->setRawResponse(json_encode($result));

            return new \WP_REST_Response([
                'success' => $result['status'] === 'success',
                'transaction' => $transaction->toArray(),
                'gateway_response' => $result,
            ]);
        } catch (\Exception $e) {
            $transaction->setStatus(Transaction::STATUS_FAILED);
            return new \WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
