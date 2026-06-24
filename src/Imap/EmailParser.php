<?php
namespace Jankx\Extensions\PaymentSystem\Imap;

class EmailParser
{
    public function parse(string $body, string $subject = ''): ?array
    {
        $parsers = apply_filters('jankx/payment/imap/parsers', [
            'bank_transfer' => [$this, 'parseBankTransfer'],
            'momo' => [$this, 'parseMoMo'],
            'vnpay' => [$this, 'parseVNPay'],
            'paypal' => [$this, 'parsePayPal'],
        ]);

        foreach ($parsers as $name => $parser) {
            $result = call_user_func($parser, $body, $subject);
            if ($result) {
                $result['parser'] = $name;
                return $result;
            }
        }

        return null;
    }

    public function parseBankTransfer(string $body, string $subject): ?array
    {
        $patterns = [
            'amount' => '/Số tiền[:\s]*([\d.,]+)/ui',
            'reference' => '/Nội dung[:\s]*(.+)/ui',
            'sender' => '/Người chuyển[:\s]*(.+)/ui',
            'account' => '/Tài khoản[:\s]*(\d+)/ui',
            'date' => '/Ngày giao dịch[:\s]*(.+)/ui',
        ];

        return $this->matchPatterns($patterns, $body, $subject, 'bank_transfer');
    }

    public function parseMoMo(string $body, string $subject): ?array
    {
        $patterns = [
            'amount' => '/Số tiền[:\s]*([\d.,]+)/ui',
            'transaction_id' => '/Mã giao dịch[:\s]*(\w+)/ui',
            'reference' => '/Nội dung[:\s]*(.+)/ui',
            'sender' => '/Từ[:\s]*(.+?)(?:\n|$)/ui',
            'phone' => '/Số điện thoại[:\s]*(\d+)/ui',
        ];

        return $this->matchPatterns($patterns, $body, $subject, 'momo');
    }

    public function parseVNPay(string $body, string $subject): ?array
    {
        $patterns = [
            'amount' => '/Số tiền[:\s]*([\d.,]+)/ui',
            'transaction_id' => '/Mã GD[:\s]*(\w+)/ui',
            'reference' => '/Nội dung[:\s]*(.+)/ui',
            'card' => '/Thẻ[:\s]*(\d+)/ui',
        ];

        return $this->matchPatterns($patterns, $body, $subject, 'vnpay');
    }

    public function parsePayPal(string $body, string $subject): ?array
    {
        $patterns = [
            'amount' => '/\b(\d+[.,]\d{2})\s*(USD|EUR|GBP)\b/i',
            'transaction_id' => '/Transaction ID[:\s]*(\w+)/i',
            'reference' => '/Payment for[:\s]*(.+)/i',
            'sender' => '/From[:\s]*(.+?)(?:\n|$)/i',
            'email' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
        ];

        return $this->matchPatterns($patterns, $body, $subject, 'paypal');
    }

    protected function matchPatterns(array $patterns, string $body, string $subject, string $type): ?array
    {
        $result = ['type' => $type];
        $matched = false;

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $body, $matches)) {
                $result[$key] = trim($matches[1]);
                $matched = true;
            } elseif ($key === 'reference' && preg_match($pattern, $subject, $matches)) {
                $result[$key] = trim($matches[1]);
                $matched = true;
            }
        }

        return $matched ? $result : null;
    }

    public function extractAmount($raw): float
    {
        if (is_numeric($raw)) {
            return (float) $raw;
        }
        return (float) str_replace([',', '.'], '', $raw);
    }

    public function extractCurrency(string $body): string
    {
        if (preg_match('/\b(VND|USD|EUR|GBP|JPY)\b/', $body, $m)) {
            return strtoupper($m[1]);
        }
        return 'VND';
    }
}
