<?php
namespace Jankx\Extensions\PaymentSystem\Gateways;

/**
 * Base class for payment gateways that need a configurable frontend display
 * on the checkout page.
 *
 * Concrete gateways implement getDefaultIcon()/getDefaultText() and may
 * override defaultDisplayType()/defaultIconPosition(). This class exposes the
 * final display API consumed by the frontend:
 *
 *  - getDisplayType()  : 'icon' (default) | 'text' | 'icon_text'
 *  - getIconPosition() : 'left' (default) | 'right' (only for icon_text)
 *  - getIcon()         : icon markup, filtered per gateway
 *  - getText()         : label, filtered per gateway
 *  - getDisplay()      : resolved payload; when the icon is empty the display
 *                        falls back to 'text'
 *
 * Per-gateway filters (e.g. jankx/payment/gateway/onepay_domestic/icon) allow
 * plugins to change icon, text, display type and icon position without
 * subclassing the gateway.
 */
abstract class AbstractGateway implements GatewayInterface
{
    public const SHOW_ICON = 'icon';
    public const SHOW_TEXT = 'text';
    public const SHOW_ICON_TEXT = 'icon_text';

    public const ICON_LEFT = 'left';
    public const ICON_RIGHT = 'right';

    /**
     * Gateway slug registered in the GatewayManager (used in filter tags).
     *
     * @var string
     */
    protected $slug = '';

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getSlug(): string
    {
        return (string) $this->slug;
    }

    /**
     * Default display type of the gateway. Concrete gateways may override.
     */
    protected function defaultDisplayType(): string
    {
        return self::SHOW_ICON;
    }

    /**
     * Default icon position for the 'icon_text' display type.
     */
    protected function defaultIconPosition(): string
    {
        return self::ICON_LEFT;
    }

    /**
     * Icon markup provided by the concrete gateway (before filtering).
     * Return an empty string to make the frontend fall back to text.
     */
    abstract protected function getDefaultIcon(): string;

    /**
     * Label provided by the concrete gateway (before filtering).
     */
    abstract protected function getDefaultText(): string;

    /**
     * Display type used on the checkout page, filtered per gateway.
     */
    public function getDisplayType(): string
    {
        $type = (string) apply_filters(
            "jankx/payment/gateway/{$this->getSlug()}/display_type",
            $this->defaultDisplayType(),
            $this
        );

        return in_array($type, [self::SHOW_ICON, self::SHOW_TEXT, self::SHOW_ICON_TEXT], true)
            ? $type
            : self::SHOW_ICON;
    }

    /**
     * Icon position for the 'icon_text' display type, filtered per gateway.
     */
    public function getIconPosition(): string
    {
        $position = (string) apply_filters(
            "jankx/payment/gateway/{$this->getSlug()}/icon_position",
            $this->defaultIconPosition(),
            $this
        );

        return in_array($position, [self::ICON_LEFT, self::ICON_RIGHT], true)
            ? $position
            : self::ICON_LEFT;
    }

    /**
     * Icon markup of the gateway, filtered per gateway.
     */
    public function getIcon(): string
    {
        return (string) apply_filters(
            "jankx/payment/gateway/{$this->getSlug()}/icon",
            $this->getDefaultIcon(),
            $this
        );
    }

    /**
     * Label of the gateway, filtered per gateway.
     */
    public function getText(): string
    {
        return (string) apply_filters(
            "jankx/payment/gateway/{$this->getSlug()}/text",
            $this->getDefaultText(),
            $this
        );
    }

    /**
     * Resolve the final display payload for the checkout frontend.
     *
     * Falls back to the text display when the icon is empty so the payment
     * method never renders as a blank button.
     *
     * @return array{type: string, icon: string, text: string, icon_position: string}
     */
    public function getDisplay(): array
    {
        $type = $this->getDisplayType();
        $icon = $type === self::SHOW_TEXT ? '' : $this->getIcon();

        if (trim($icon) === '') {
            $type = self::SHOW_TEXT;
            $icon = '';
        }

        return [
            'type'         => $type,
            'icon'         => $icon,
            'text'         => $this->getText(),
            'icon_position' => $this->getIconPosition(),
        ];
    }
}
