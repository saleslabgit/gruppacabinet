<?php

namespace Tests\Support;

use App\Models\Payment;
use App\Payments\Webpay;

class WebpayFixture
{
    public static function configure(): void
    {
        config(['webpay.environment' => 'sandbox', 'webpay.store_id' => '11111111', 'webpay.secret_key' => 'synthetic-secret',
            'webpay.api_username' => 'synthetic-user', 'webpay.api_password' => 'synthetic-password']);
    }

    public static function notify(Payment $payment, array $changes = []): array
    {
        $fields = array_replace(['batch_timestamp' => '1790000000', 'currency_id' => 'BYN', 'amount' => Webpay::decimal($payment->amount),
            'payment_method' => 'cc', 'order_id' => '123456', 'site_order_id' => $payment->order_number,
            'transaction_id' => '987654', 'payment_type' => '1', 'rrn' => '555444333222'], $changes);
        $fields['wsb_signature'] = md5(implode('', array_map(fn ($key) => $fields[$key], Webpay::NOTIFY_FIELDS)).'synthetic-secret');

        return $fields;
    }

    public static function xml(array $notify, array $changes = []): string
    {
        $fields = array_replace($notify, $changes);
        $api = array_intersect_key($fields, array_flip(Webpay::API_FIELDS));
        $api['wsb_signature'] = md5(implode('', array_map(fn ($key) => $api[$key], Webpay::API_FIELDS)).'synthetic-secret');
        $xml = '<wsb_api_response><fields>';
        foreach ($api as $key => $value) {
            $xml .= '<'.$key.'>'.htmlspecialchars($value, ENT_XML1).'</'.$key.'>';
        }

        return $xml.'</fields></wsb_api_response>';
    }
}
