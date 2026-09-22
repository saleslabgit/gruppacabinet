<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentIndexRequest;
use App\Http\Requests\PaymentRefundRequest;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Webpay;
use App\Services\AuditService;
use App\Support\PaymentPages;
use App\Support\PsychologistPages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function index(PaymentIndexRequest $request)
    {
        $filters = $request->validated();
        $query = Payment::query()->with(['owner' => fn ($q) => $q->withTrashed(), 'group' => fn ($q) => $q->withTrashed()]);
        if ($search = $filters['search'] ?? null) {
            $query->where(fn ($q) => $q->where('order_number', 'like', '%'.$search.'%')->orWhere('transaction_id', 'like', '%'.$search.'%'));
        }
        foreach (['status', 'type', 'owner_id'] as $key) {
            if ($value = $filters[$key] ?? null) {
                $query->where($key, $value);
            }
        }
        if ($from = $filters['from'] ?? null) {
            $query->where('created_at', '>=', Carbon::parse($from, 'Europe/Minsk')->startOfDay()->utc());
        }
        if ($to = $filters['to'] ?? null) {
            $query->where('created_at', '<', Carbon::parse($to, 'Europe/Minsk')->addDay()->startOfDay()->utc());
        }
        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.payments.index', array_merge(PsychologistPages::layout('Платежи'), [
            'realPayments' => true, 'filters' => $filters, 'payments' => $paginator->getCollection()->map(fn ($p) => PaymentPages::data($p)),
            'empty' => $paginator->isEmpty(), 'ownerOptions' => ['' => 'Все'] + User::withTrashed()->where('admin', false)->orderBy('last_name')->orderBy('id')->get()
                ->mapWithKeys(fn ($u) => [$u->id => PsychologistPages::profile($u)['name']])->all(),
            'pages' => $paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)),
            'currentPage' => $paginator->currentPage(),
        ]));
    }

    public function show(Payment $payment)
    {
        Gate::authorize('view', $payment);
        $journal = $payment->notifications()->latest('id')->paginate(20);

        return view('admin.payments.show', PaymentPages::detail($payment, true) + [
            'notifications' => $journal->getCollection()->map(fn ($n) => ['created_at' => $n->created_at, 'result' => $n->result,
                'signature_valid' => $n->signature_valid, 'payload' => Webpay::safeFields($n->payload)]),
            'pages' => $journal->getUrlRange(max(1, $journal->currentPage() - 2), min($journal->lastPage(), $journal->currentPage() + 2)),
            'currentPage' => $journal->currentPage(),
        ]);
    }

    public function refund(PaymentRefundRequest $request, Payment $payment)
    {
        DB::transaction(function () use ($request, $payment): void {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            Gate::authorize('refund', $locked);
            $locked->update(['status' => PaymentStatus::Refunded, 'refunded_at' => now()->utc(), 'refund_comment' => $request->validated('refund_comment')]);
            app(AuditService::class)->record('payment', $locked->id, 'payment.refunded', [], $request->user());
        });

        return redirect()->route('admin.payments.show', $payment)->with('success', 'Возврат, выполненный в WEBPAY, учтён.');
    }
}
