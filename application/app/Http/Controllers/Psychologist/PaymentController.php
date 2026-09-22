<?php

namespace App\Http\Controllers\Psychologist;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\PaymentAttempts;
use App\Payments\PaymentRecovery;
use App\Support\PaymentPages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    private function owned(Request $request, string $payment): Payment
    {
        $model = Payment::where('owner_id', $request->user()->id)->findOrFail($payment);
        Gate::authorize('view', $model);

        return $model;
    }

    public function show(Request $request, string $payment)
    {
        $model = $this->owned($request, $payment);

        return view($model->status === PaymentStatus::Created ? 'psychologist.payments.placement' : 'psychologist.payments.return', PaymentPages::detail($model, false));
    }

    public function result(Request $request, string $payment, PaymentRecovery $recovery)
    {
        $model = $this->owned($request, $payment);
        // Browser parameters deliberately do not participate in binding or API selection.
        $recovery->check($model->id);

        return $this->show($request, $payment);
    }

    public function start(Request $request, string $payment, PaymentAttempts $attempts)
    {
        $model = $this->owned($request, $payment);
        $form = $attempts->start($model);

        return response()->view('psychologist.payments.placement', PaymentPages::detail($model->fresh(), false) + ['providerForm' => $form])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function retry(Request $request, string $payment, PaymentAttempts $attempts)
    {
        $next = $attempts->retry($this->owned($request, $payment), $request->user());

        return $next instanceof Payment ? redirect()->route('psychologist.payments.show', $next)
            : redirect()->route('psychologist.groups.show', $next)->with('success', 'Продление выполнено.');
    }
}
