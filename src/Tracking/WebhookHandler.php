<?php
namespace Jankx\Extensions\PaymentSystem\Tracking;

use Jankx\Extensions\PaymentSystem\Models\Transaction;
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

class WebhookHandler
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
        register_rest_route('jankx/v1', '/payment/webhook/(?P<gateway>[a-zA-Z0-9_-]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handleWebhook'],
            'permission_callback' => '__return_true',
            'args' => [
                'gateway' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function handleWebhook(\WP_REST_Request $request): \WP_REST_Response
    {
        $gatewayName = $request->get_param('gateway');
        $payload = $request->get_body();

        do_action('jankx/payment/webhook_received', $gatewayName, $payload);

        $gateway = $this->gatewayManager->get($gatewayName);
        if (!$gateway) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => sprintf('Gateway %s not found', $gatewayName),
            ], 404);
        }

        $result = apply_filters(
            "jankx/payment/webhook/{$gatewayName}",
            ['success' => false, 'message' => 'Unhandled'],
            $payload,
            $request
        );

        if ($result['success'] && !empty($result['transaction_id'])) {
            $transaction = Transaction::find($result['transaction_id'], $gatewayName);
            if ($transaction) {
                $newStatus = $result['status'] ?? Transaction::STATUS_COMPLETED;
                $transaction->setStatus($newStatus);
                $transaction->setRawResponse($payload);
                do_action('jankx/payment/webhook_processed', $transaction, $result);
            }
        }

        return new \WP_REST_Response($result);
    }
}
