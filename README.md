# Payment System Extension

## Tổng quan

**Payment System** là extension core của Jankx Theme, cung cấp hệ thống xử lý thanh toán online thống nhất. Các extension khác có thể khai báo dependency để sử dụng chung cơ sở hạ tầng này.

**Các thành phần chính:**

- **Gateway abstraction** – interface chuẩn cho mọi cổng thanh toán (Omnipay, MoMo, VNPay, …)
- **Transaction model** – CPT `jankx_transaction` lưu toàn bộ giao dịch
- **API Tracking** – WP CRON tự động dò trạng thái giao dịch pending
- **Webhook Handler** – REST endpoint cho gateway callback
- **IMAP Monitor** – đọc email thông báo thanh toán (MoMo, VNPay, bank transfer, PayPal)
- **Admin UI** – settings page + transaction list table

---

## Cài đặt

### Yêu cầu

- PHP ≥ 7.4
- WordPress ≥ 5.0
- Composer (đã cài trong extension)
- (Tuỳ chọn) PHP IMAP extension — nếu dùng IMAP Monitor

### Kích hoạt

Extension tự động kích hoạt (`auto_activate: true` trong `manifest.json`). Sau khi theme Jankx load, extension được khởi tạo qua caller:

```json
{
  "caller": {
    "file": "PaymentSystemExtension.php",
    "class": "Jankx\\Extensions\\PaymentSystem\\PaymentSystemExtension",
    "method": "init"
  }
}
```

### Cấu hình

Vào **Jankx → Payments** trong admin:

| Tab | Mô tả |
|-----|-------|
| General | Currency (VND/USD/EUR), Default Gateway |
| Gateways | API keys cho từng gateway |
| IMAP Monitor | Host, port, username, password, SSL, lookback days |

---

## Cách extension khác sử dụng

### 1. Khai báo dependency

Trong `manifest.json` của extension con:

```json
{
  "extension_id": "my-payment-addon",
  "dependencies": {
    "extensions": ["payment-system"]
  }
}
```

### 2. Đăng ký gateway

Trong phương thức `init()` của extension con:

```php
use Jankx\Extensions\PaymentSystem\Gateways\GatewayManager;

add_action('jankx/payment/register_gateways', function () {
    $manager = GatewayManager::getInstance();
    $manager->register('momo', MoMoGateway::class);
});
```

Gateway class phải implement `GatewayInterface`:

```php
use Jankx\Extensions\PaymentSystem\Gateways\GatewayInterface;

class MoMoGateway implements GatewayInterface
{
    public function getName(): string       { return 'momo'; }
    public function initialize(array $parameters): void { /* lưu config */ }
    public function purchase(array $params): array      { /* gọi API MoMo */ }
    public function completePurchase(array $params): array { /* xử lý return */ }
    public function refund(array $params): array        { /* hoàn tiền */ }
    public function queryStatus(string $txnId): string  { /* dò trạng thái */ }
    public function isAvailable(): bool                 { /* kiểm tra config */ }
    public function getSettingsFields(): array          { /* fields cho admin */ }
}
```

### 3. Dùng Omnipay (PayPal, Stripe, …)

Không cần tự viết gateway class — dùng `OmnipayGateway` adapter:

```php
add_action('jankx/payment/register_gateways', function () {
    $manager = GatewayManager::getInstance();
    $manager->register(
        'paypal_express',
        \Jankx\Extensions\PaymentSystem\Gateways\OmnipayGateway::class
    );
});
```

Config được lưu trong Settings → Gateways (các field do Omnipay gateway định nghĩa).

### 4. Tạo giao dịch từ frontend

Gọi REST API:

```bash
POST /wp-json/jankx/v1/payment/create
Body: {
  "gateway": "paypal_express",
  "amount": 100000,
  "currency": "VND",
  "order_id": 123,
  "return_url": "https://example.com/callback",
  "cancel_url": "https://example.com/cancel"
}
Headers: X-WP-Nonce: ...
```

Phản hồi:

```json
{
  "success": true,
  "transaction_id": 42,
  "payment": {
    "status": "redirect",
    "redirectUrl": "https://paypal.com/...",
    "redirectMethod": "GET",
    "transactionId": "PAY-123"
  }
}
```

### 5. Xử lý return URL

Sau khi gateway redirect về `return_url`, gọi:

```bash
POST /wp-json/jankx/v1/payment/{id}/process
Body: { ... gateway response params ... }
```

### 6. Kiểm tra trạng thái

```bash
GET /wp-json/jankx/v1/payment/{id}/status
```

### 7. Danh sách gateway

```bash
GET /wp-json/jankx/v1/gateways
```

---

## REST API Endpoints

| Method | Route | Auth | Mô tả |
|--------|-------|------|-------|
| GET | `/wp-json/jankx/v1/gateways` | public | Danh sách gateway có sẵn |
| POST | `/wp-json/jankx/v1/payment/create` | logged-in | Tạo giao dịch mới |
| GET | `/wp-json/jankx/v1/payment/{id}/status` | logged-in | Tra trạng thái giao dịch |
| POST | `/wp-json/jankx/v1/payment/{id}/process` | public | Xử lý return từ gateway |
| POST | `/wp-json/jankx/v1/payment/webhook/{gateway}` | public | Webhook từ gateway |

---

## Transaction Lifecycle

```
pending ──► processing ──► completed
  │                          │
  └──► failed                └──► refunded
  │
  └──► cancelled
```

- **pending**: vừa tạo, chờ thanh toán
- **processing**: gateway đang xử lý (dùng cho tracking)
- **completed**: thanh toán thành công
- **failed**: thanh toán thất bại
- **refunded**: đã hoàn tiền
- **cancelled**: người dùng huỷ

---

## Auto Tracking

CRON chạy mỗi giờ, dò các giao dịch `pending` và gọi `queryStatus()` của gateway tương ứng. Nếu thành công → chuyển `completed`, nếu thất bại → giữ nguyên (sẽ thử lại ở lần chạy sau).

## Webhook

Extension đăng ký endpoint `/jankx/v1/payment/webhook/{gateway}` không yêu cầu auth. Gateway handler tự xác thực signature. Khi nhận webhook:

1. Parse payload, tìm transaction theo `transaction_id`
2. Cập nhật trạng thái
3. Log raw response

## IMAP Monitor

CRON chạy 5 phút, đọc email chưa đọc từ hộp thư. Dùng filter `jankx/payment/imap/parsers` để extension khác đăng ký parser cho format email riêng:

```php
add_filter('jankx/payment/imap/parsers', function ($parsers) {
    $parsers['bank_transfer'] = MyBankTransferParser::class;
    return $parsers;
});
```

Yêu cầu PHP extension `imap`.

---

## Hooks & Filters

### Actions

| Hook | Tham số | Mô tả |
|------|---------|-------|
| `jankx/payment/register_gateways` | (none) | Đăng ký gateway |
| `jankx/payment/transaction_status_changed` | `$transaction, $new_status` | Khi trạng thái thay đổi |
| `jankx/payment/admin/gateway_settings_before` | (none) | Trước khi render gateway settings |
| `jankx/payment/admin/gateway_settings_after` | (none) | Sau khi render gateway settings |

### Filters

| Filter | Tham số | Mô tả |
|--------|---------|-------|
| `jankx/payment/gateway/{name}/default_config` | `$defaults` | Config mặc định cho gateway |
| `jankx/payment/imap/parsers` | `$parsers` | Đăng ký IMAP email parsers |

---

## Cấu trúc thư mục

```
extensions/payment-system/
├── manifest.json
├── composer.json
├── PaymentSystemExtension.php        # Caller — init + hooks
├── vendor/                            # Composer dependencies
├── src/
│   ├── Gateways/
│   │   ├── GatewayInterface.php       # Contract cho mọi gateway
│   │   ├── GatewayManager.php         # Registry singleton
│   │   └── OmnipayGateway.php         # Omnipay adapter
│   ├── Models/
│   │   └── Transaction.php            # CPT + CRUD + finders
│   ├── Tracking/
│   │   ├── ApiTracker.php             # CRON hourly
│   │   └── WebhookHandler.php         # REST endpoint
│   ├── Imap/
│   │   ├── ImapMonitor.php            # CRON 5 phút
│   │   └── EmailParser.php            # Parse MoMo, VNPay, bank, PayPal
│   ├── Admin/
│   │   ├── SettingsPage.php           # Settings page (3 tabs)
│   │   └── TransactionListTable.php   # WP_List_Table
│   └── Rest/
│       └── PaymentController.php      # REST API endpoints
└── tests/
    ├── bootstrap.php
    ├── Gateways/
    │   ├── GatewayManagerTest.php
    │   └── OmnipayGatewayTest.php
    └── Models/
        └── TransactionTest.php
```

---

## Ví dụ: Extension thanh toán MoMo

```php
// extensions/momo-payment/manifest.json
{
  "extension_id": "momo-payment",
  "dependencies": {
    "extensions": ["payment-system"]
  },
  "auto_activate": true
}
```

```php
// extensions/momo-payment/MoMoPaymentExtension.php
class MoMoPaymentExtension extends AbstractExtension
{
    public function init() {
        add_action('jankx/payment/register_gateways', [$this, 'registerGateway']);
    }

    public function registerGateway() {
        $manager = GatewayManager::getInstance();
        $manager->register('momo', MoMoGateway::class);
    }
}
```

---

## Chạy test

```bash
cd extensions/payment-system
./vendor/bin/phpunit
```
