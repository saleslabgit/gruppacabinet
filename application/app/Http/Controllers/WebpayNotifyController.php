<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentNotification;
use App\Payments\ConfirmPayment;
use App\Payments\ProviderException;
use App\Payments\Webpay;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebpayNotifyController extends Controller
{
    public function __invoke(Request $request, Webpay $provider, ConfirmPayment $confirmation): Response
    {
        $safe = Webpay::safeFields($request->post());
        $journal = PaymentNotification::create(['payload' => $safe, 'order_number' => $safe['site_order_id'] ?? null,
            'transaction_id' => $safe['transaction_id'] ?? null, 'signature_valid' => false, 'processed' => false, 'result' => 'received']);
        try {
            $result = $provider->notify($request->post());
            $journal->update(['signature_valid' => true]);
            $payment = Payment::query()->where('order_number', $result->merchantOrder)->first();
            if (! $payment) {
                throw new ProviderException('unknown_order');
            }
            $journal->update(['payment_id' => $payment->id]);
            $outcome = $confirmation->apply($payment->id, $result);
            $journal->update(['processed' => true, 'result' => $outcome]);

            return response('OK', 200);
        } catch (ProviderException $exception) {
            $journal->update(['result' => $exception->getMessage()]);
            Log::warning('WEBPAY notify rejected.', ['payment_id' => $journal->payment_id, 'code' => $exception->getMessage()]);

            return response('Rejected', 400);
        } catch (Throwable) {
            $journal->update(['result' => 'processing_unavailable']);
            Log::error('WEBPAY notify unavailable.', ['payment_id' => $journal->payment_id, 'code' => 'processing_unavailable']);

            return response('Unavailable', 503);
        }
    }
}
