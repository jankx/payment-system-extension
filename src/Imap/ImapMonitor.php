<?php
namespace Jankx\Extensions\PaymentSystem\Imap;

use Jankx\Extensions\PaymentSystem\Models\Transaction;

class ImapMonitor
{
    protected $parser;

    protected $connection;

    public function __construct()
    {
        $this->parser = new EmailParser();
    }

    public function init(): void
    {
        add_action('jankx/payment/cron/check_imap', [$this, 'checkInbox']);
        add_filter('cron_schedules', [$this, 'addCronSchedule']);

        if (!wp_next_scheduled('jankx/payment/cron/check_imap')) {
            wp_schedule_event(time(), 'jankx_imap_interval', 'jankx/payment/cron/check_imap');
        }
    }

    public function addCronSchedule(array $schedules): array
    {
        $schedules['jankx_imap_interval'] = [
            'interval' => apply_filters('jankx/payment/imap/interval', 300),
            'display' => __('Every 5 minutes (IMAP)', 'jankx'),
        ];
        return $schedules;
    }

    protected function getConfig(): array
    {
        return apply_filters('jankx/payment/imap/config', [
            'hostname' => get_option('jankx_payment_imap_host', ''),
            'port' => get_option('jankx_payment_imap_port', 993),
            'username' => get_option('jankx_payment_imap_username', ''),
            'password' => get_option('jankx_payment_imap_password', ''),
            'ssl' => get_option('jankx_payment_imap_ssl', true),
            'mailbox' => get_option('jankx_payment_imap_mailbox', 'INBOX'),
            'since_days' => get_option('jankx_payment_imap_since_days', 7),
        ]);
    }

    public function checkInbox(): void
    {
        if (!function_exists('imap_open')) {
            return;
        }

        $config = $this->getConfig();
        if (empty($config['hostname']) || empty($config['username']) || empty($config['password'])) {
            return;
        }

        $mailbox = sprintf(
            '{%s:%d/%s%s}%s',
            $config['hostname'],
            $config['port'],
            $config['ssl'] ? 'imap/ssl' : 'imap/notls',
            !empty($config['novalidate']) ? '/novalidate-cert' : '',
            $config['mailbox']
        );

        $this->connection = @imap_open($mailbox, $config['username'], $config['password']);
        if (!$this->connection) {
            error_log('[PaymentSystem] IMAP connection failed: ' . implode(', ', imap_errors()));
            return;
        }

        try {
            $this->fetchUnseenEmails($config);
        } finally {
            imap_close($this->connection);
        }
    }

    protected function fetchUnseenEmails(array $config): void
    {
        $since = date('d-M-Y', strtotime("-{$config['since_days']} days"));
        $emails = @imap_search($this->connection, "UNSEEN SINCE {$since}");

        if (!$emails) {
            return;
        }

        $processed = 0;
        $maxEmails = apply_filters('jankx/payment/imap/max_emails', 20);

        foreach ($emails as $msgId) {
            if ($processed >= $maxEmails) {
                break;
            }

            try {
                $this->processEmail($msgId);
                $processed++;
            } catch (\Exception $e) {
                error_log('[PaymentSystem] Failed to process email: ' . $e->getMessage());
            }
        }
    }

    protected function processEmail(int $msgId): void
    {
        $structure = @imap_fetchstructure($this->connection, $msgId);
        $subject = @imap_fetchheader($this->connection, $msgId, FT_PREFETCHTEXT);
        $body = $this->getEmailBody($msgId, $structure);

        if (empty($body)) {
            return;
        }

        preg_match('/Subject:\s*(.+)/i', $subject, $subjectMatch);
        $emailSubject = trim($subjectMatch[1] ?? '');

        $parsed = $this->parser->parse($body, $emailSubject);
        if (!$parsed) {
            return;
        }

        $transactionId = $parsed['transaction_id'] ?? $parsed['reference'] ?? '';
        if (empty($transactionId)) {
            return;
        }

        $transaction = Transaction::find($transactionId);
        if (!$transaction) {
            $amount = isset($parsed['amount']) ? $this->parser->extractAmount($parsed['amount']) : 0;
            $transaction = Transaction::create([
                'title' => sprintf(__('IMAP: %s', 'jankx'), $emailSubject),
                'gateway' => 'email_' . ($parsed['parser'] ?? 'unknown'),
                'amount' => $amount,
                'currency' => $this->parser->extractCurrency($body),
                'status' => Transaction::STATUS_COMPLETED,
                'transaction_id' => sanitize_text_field($transactionId),
                'customer_email' => $parsed['email'] ?? $parsed['sender'] ?? '',
                'raw_request' => $body,
            ]);
        } else {
            $transaction->setStatus(Transaction::STATUS_COMPLETED);
            $transaction->setRawResponse($body);
        }

        do_action('jankx/payment/imap/email_processed', $transaction, $parsed);
    }

    protected function getEmailBody(int $msgId, $structure): string
    {
        if (!empty($structure->parts)) {
            foreach ($structure->parts as $part) {
                if ($part->subtype === 'PLAIN' || $part->subtype === 'HTML') {
                    $body = @imap_fetchbody($this->connection, $msgId, $part->subtype === 'HTML' ? '2' : '1');
                    if ($part->encoding === 3) {
                        $body = base64_decode($body);
                    } elseif ($part->encoding === 4) {
                        $body = quoted_printable_decode($body);
                    }
                    return $body ?: '';
                }
            }
            return '';
        }

        $body = @imap_body($this->connection, $msgId);
        if ($structure?->encoding === 3) {
            $body = base64_decode($body);
        } elseif ($structure?->encoding === 4) {
            $body = quoted_printable_decode($body);
        }
        return $body ?: '';
    }
}
