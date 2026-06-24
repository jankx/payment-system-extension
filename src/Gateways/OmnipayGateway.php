<?php
namespace Jankx\Extensions\PaymentSystem\Gateways;

use Omnipay\Common\GatewayFactory;

class OmnipayGateway implements GatewayInterface
{
    protected $omnipayGateway;

    protected $gatewayName;

    protected $displayName;

    public function __construct(string $omnipayName, string $displayName = '')
    {
        $this->gatewayName = $omnipayName;
        $this->displayName = $displayName ?: $omnipayName;
    }

    public function getName(): string
    {
        return $this->gatewayName;
    }

    public function initialize(array $parameters): void
    {
        $factory = new GatewayFactory();
        $this->omnipayGateway = $factory->create($this->gatewayName);
        $this->omnipayGateway->initialize($parameters);
    }

    public function purchase(array $parameters): array
    {
        $request = $this->omnipayGateway->purchase($parameters);
        $response = $request->send();

        if ($response->isRedirect()) {
            return [
                'status' => 'redirect',
                'redirectUrl' => $response->getRedirectUrl(),
                'redirectMethod' => $response->getRedirectMethod(),
                'redirectData' => $response->getRedirectData(),
                'transactionId' => $response->getTransactionReference() ?? '',
            ];
        }

        if ($response->isSuccessful()) {
            return [
                'status' => 'success',
                'transactionId' => $response->getTransactionReference(),
                'message' => $response->getMessage(),
            ];
        }

        return [
            'status' => 'failed',
            'message' => $response->getMessage(),
            'code' => $response->getCode(),
        ];
    }

    public function completePurchase(array $parameters): array
    {
        $request = $this->omnipayGateway->completePurchase($parameters);
        $response = $request->send();

        if ($response->isSuccessful()) {
            return [
                'status' => 'success',
                'transactionId' => $response->getTransactionReference(),
                'message' => $response->getMessage(),
            ];
        }

        return [
            'status' => 'failed',
            'message' => $response->getMessage(),
            'code' => $response->getCode(),
        ];
    }

    public function refund(array $parameters): array
    {
        $request = $this->omnipayGateway->refund($parameters);
        $response = $request->send();

        if ($response->isSuccessful()) {
            return [
                'status' => 'success',
                'transactionId' => $response->getTransactionReference(),
                'message' => $response->getMessage(),
            ];
        }

        return [
            'status' => 'failed',
            'message' => $response->getMessage(),
            'code' => $response->getCode(),
        ];
    }

    public function queryStatus(string $transactionId): string
    {
        $gateway = $this->omnipayGateway;
        if (is_null($gateway) || !method_exists($gateway, 'fetchTransaction')) {
            return 'unknown';
        }
        try {
            $request = $gateway->fetchTransaction(['transactionReference' => $transactionId]);
            $response = $request->send();
            if ($response->isSuccessful()) {
                return $response->isPaid() ? 'completed' : 'pending';
            }
        } catch (\Exception $e) {
            return 'unknown';
        }
        return 'unknown';
    }

    public function isAvailable(): bool
    {
        return !is_null($this->omnipayGateway);
    }

    public function getSettingsFields(): array
    {
        return [];
    }
}
