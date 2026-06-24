<?php
namespace Jankx\Extensions\PaymentSystem\Gateways;

interface GatewayInterface
{
    public function getName(): string;

    public function initialize(array $parameters): void;

    public function purchase(array $parameters): array;

    public function completePurchase(array $parameters): array;

    public function refund(array $parameters): array;

    public function queryStatus(string $transactionId): string;

    public function isAvailable(): bool;

    public function getSettingsFields(): array;
}
