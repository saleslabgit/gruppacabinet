<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Throwable;

class Webpay
{
    public const NOTIFY_FIELDS = ['batch_timestamp', 'currency_id', 'amount', 'payment_method', 'order_id', 'site_order_id', 'transaction_id', 'payment_type', 'rrn'];

    public const API_FIELDS = ['transaction_id', 'batch_timestamp', 'currency_id', 'amount', 'payment_method', 'payment_type', 'order_id', 'rrn'];

    public function configuration(bool $api = false): array
    {
        $config = config('webpay');
        if (! in_array($config['environment'], ['sandbox', 'production'], true)) {
            throw new ProviderException('configuration_missing');
        }
        foreach ($api ? ['store_id', 'secret_key', 'api_username', 'api_password'] : ['store_id', 'secret_key'] as $key) {
            if (! is_string($config[$key]) || trim($config[$key]) === '') {
                throw new ProviderException('configuration_missing');
            }
        }
        $sandbox = $config['environment'] === 'sandbox';

        return $config + ['payment_url' => $sandbox ? 'https://securesandbox.webpay.by/' : 'https://payment.webpay.by/',
            'api_url' => $sandbox ? 'https://sandbox.webpay.by' : 'https://billing.webpay.by', 'test' => $sandbox ? '1' : '0'];
    }

    public static function decimal(int $minor): string
    {
        if ($minor < 0) {
            throw new ProviderException('invalid_amount');
        }

        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function minor(string $decimal): int
    {
        if (! preg_match('/\A(0|[1-9][0-9]{0,16})(?:\.([0-9]{1,2}))?\z/', $decimal, $parts)) {
            throw new ProviderException('invalid_amount');
        }

        $digits = ltrim($parts[1].str_pad($parts[2] ?? '', 2, '0'), '0') ?: '0';
        $maximum = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new ProviderException('invalid_amount');
        }

        return (int) $digits;
    }

    public function form(Payment $payment, ?string $seed = null): array
    {
        $config = $this->configuration();
        $fields = ['*scart' => '', 'wsb_version' => '2', 'wsb_storeid' => $config['store_id'],
            'wsb_order_num' => $payment->order_number, 'wsb_test' => $config['test'], 'wsb_currency_id' => 'BYN',
            'wsb_seed' => $seed ?? bin2hex(random_bytes(16)), 'wsb_total' => self::decimal($payment->amount),
            'wsb_invoice_item_name[0]' => $payment->type === 'placement' ? 'Размещение группы' : 'Продление размещения группы',
            'wsb_invoice_item_quantity[0]' => '1', 'wsb_invoice_item_price[0]' => self::decimal($payment->amount),
            'wsb_return_url' => route('psychologist.payments.return', $payment),
            'wsb_cancel_return_url' => route('psychologist.payments.cancel', $payment),
            'wsb_notify_url' => route('webpay.notify')];
        $fields['wsb_signature'] = sha1($fields['wsb_seed'].$fields['wsb_storeid'].$fields['wsb_order_num'].$fields['wsb_test'].$fields['wsb_currency_id'].$fields['wsb_total'].$config['secret_key']);

        return ['url' => $config['payment_url'], 'fields' => $fields];
    }

    public function notify(array $input): ProviderResult
    {
        // Card-inclusive signatures belong to a different merchant configuration.
        if (isset($input['card'])) {
            throw new ProviderException('unsupported_card_mode');
        }
        $fields = $this->verify($input, self::NOTIFY_FIELDS);

        return new ProviderResult($fields, $fields['site_order_id']);
    }

    private function verify(array $input, array $keys): array
    {
        $config = $this->configuration();
        $fields = [];
        foreach ($keys as $key) {
            if (! isset($input[$key]) || ! is_string($input[$key]) || strlen($input[$key]) > 128) {
                throw new ProviderException('invalid_fields');
            }
            $fields[$key] = $input[$key];
        }
        $signature = $input['wsb_signature'] ?? null;
        if (! is_string($signature) || ! preg_match('/\A[0-9a-fA-F]{32}\z/', $signature)
            || ! hash_equals(md5(implode('', $fields).$config['secret_key']), strtolower($signature))) {
            throw new ProviderException('invalid_signature');
        }
        foreach (['transaction_id', 'order_id', 'batch_timestamp', 'payment_type'] as $key) {
            if (! preg_match('/\A[0-9]{1,32}\z/', $fields[$key])) {
                throw new ProviderException('invalid_fields');
            }
        }
        self::minor($fields['amount']);

        return $fields;
    }

    public function transaction(string $transactionId): ProviderResult
    {
        $config = $this->configuration(true);
        if (! preg_match('/\A[0-9]{1,32}\z/', $transactionId)) {
            throw new ProviderException('invalid_transaction');
        }
        $username = htmlspecialchars($config['api_username'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><wsb_api_request><command>get_transaction</command><authorization><username>'.$username.'</username><password>'.md5($config['api_password']).'</password></authorization><fields><transaction_id>'.$transactionId.'</transaction_id></fields></wsb_api_request>';
        try {
            $response = Http::asForm()->withOptions(['verify' => true, 'allow_redirects' => false])
                ->connectTimeout(5)->timeout(max(1, min(30, $config['http_timeout'])))
                ->post($config['api_url'], ['*API' => '', 'API_XML_REQUEST' => $xml]);
        } catch (Throwable) {
            throw new ProviderException('provider_unavailable');
        }
        if (! $response->successful()) {
            throw new ProviderException('provider_unavailable');
        }
        $body = $response->body();
        if ($body === '' || strlen($body) > 65536 || preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            throw new ProviderException('invalid_xml');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            // DOM/libxml are already required by the production Composer dependency set.
            $document = new \DOMDocument;
            if (! $document->loadXML($body, LIBXML_NONET) || $document->doctype !== null) {
                throw new ProviderException('invalid_xml');
            }
            $xpath = new \DOMXPath($document);
            $input = [];
            foreach ([...self::API_FIELDS, 'wsb_signature'] as $key) {
                $nodes = $xpath->query('//'.$key);
                if ($nodes === false || $nodes->length !== 1) {
                    throw new ProviderException('invalid_xml');
                }
                $node = $nodes->item(0);
                foreach ($node->childNodes as $child) {
                    if ($child instanceof \DOMElement) {
                        throw new ProviderException('invalid_xml');
                    }
                }
                $input[$key] = $node->textContent;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $fields = $this->verify($input, self::API_FIELDS);
        if ($fields['transaction_id'] !== $transactionId) {
            throw new ProviderException('transaction_mismatch');
        }

        return new ProviderResult($fields, null);
    }

    public static function safeFields(array $input): array
    {
        // Validate values too: an arbitrary body can put sensitive text in a whitelisted key.
        $patterns = ['site_order_id' => '/\AGP-[a-f0-9]{32}\z/', 'transaction_id' => '/\A[0-9]{1,32}\z/',
            'order_id' => '/\A[0-9]{1,32}\z/', 'currency_id' => '/\A[A-Z]{3}\z/',
            'amount' => '/\A[0-9]{1,17}(?:\.[0-9]{1,2})?\z/', 'payment_method' => '/\A(?:cc|test|erip|apple)\z/',
            'payment_type' => '/\A[0-9]{1,3}\z/', 'batch_timestamp' => '/\A[0-9]{1,12}\z/'];
        $safe = [];
        foreach ($patterns as $key => $pattern) {
            if (isset($input[$key]) && is_string($input[$key]) && preg_match($pattern, $input[$key])) {
                $safe[$key] = $input[$key];
            }
        }

        return $safe;
    }
}
