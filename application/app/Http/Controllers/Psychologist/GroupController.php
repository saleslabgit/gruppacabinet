<?php

namespace App\Http\Controllers\Psychologist;

use App\Enums\GroupStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupActionRequest;
use App\Http\Requests\GroupRequest;
use App\Models\Group;
use App\Models\Payment;
use App\Payments\PaymentAttempts;
use App\Services\GroupWorkflow;
use App\Services\SettingService;
use App\Support\GroupPages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GroupController extends Controller
{
    public function index(Request $request): View
    {
        $paginator = Group::query()->where('owner_id', $request->user()->id)->with(['format', 'gender'])->withCount(Group::applicationCounts())
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20);
        $paginator->getCollection()->each(fn (Group $group) => $group->setRelation('owner', $request->user()));

        return view('psychologist.groups.index', array_merge(GroupPages::layout('Мои группы', false), [
            'groups' => GroupPages::listing($paginator->getCollection()),
            'empty' => $paginator->isEmpty(), 'canCreateGroup' => true,
            'pages' => $paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)),
            'currentPage' => $paginator->currentPage(),
        ]));
    }

    public function store(Request $request, GroupWorkflow $workflow): RedirectResponse
    {
        $group = $workflow->create($request->user(), $request->user());

        if ($group->status === GroupStatus::AwaitingPayment) {
            return redirect()->route('psychologist.payments.show', $group->payments()->latest('id')->firstOrFail());
        }

        return redirect()->route('psychologist.groups.edit', $group)->with('success', 'Черновик создан.');
    }

    public function show(Request $request, string $group): View
    {
        $model = Group::query()->where('owner_id', $request->user()->id)->withCount(Group::applicationCounts())->findOrFail($group);
        Gate::authorize('view', $model);

        return view('psychologist.groups.show', GroupPages::detail($model, false) + [
            'latestApplication' => $model->applications()->orderByDesc('created_at')->orderByDesc('id')->first(),
        ]);
    }

    public function edit(Request $request, string $group): View
    {
        $model = Group::query()->where('owner_id', $request->user()->id)->findOrFail($group);
        Gate::authorize('update', $model);

        return view('psychologist.groups.form', GroupPages::form($model, false));
    }

    public function extension(Request $request, string $group): View
    {
        $model = Group::query()->where('owner_id', $request->user()->id)->findOrFail($group);
        Gate::authorize('extend', $model);

        return view('psychologist.groups.extension', array_replace(GroupPages::detail($model, false, false), ['title' => 'Продление размещения', 'extensionPrice' => app(SettingService::class)->extensionPriceMinorUnits(),
            'pendingPayment' => $model->payments()->where('type', 'extension')->whereIn('status', ['created', 'pending'])->first()]));
    }

    public function extend(GroupActionRequest $request, PaymentAttempts $attempts): RedirectResponse
    {
        $group = $attempts->extend($request->group(), $request->user());
        if ($group instanceof Payment) {
            return redirect()->route('psychologist.payments.show', $group);
        }

        return redirect()->route('psychologist.groups.show', $group)->with('success', 'Продление выполнено.');
    }

    public function update(GroupRequest $request, GroupWorkflow $workflow): RedirectResponse
    {
        $group = $workflow->save($request->group(), $request->user(), $request->content(), $request->routeIs('*.submit'));

        return redirect()->route('psychologist.groups.show', $group)->with('success', 'Изменения сохранены.');
    }

    public function destroy(GroupActionRequest $request, GroupWorkflow $workflow): RedirectResponse
    {
        $workflow->delete($request->group(), $request->user());

        return redirect()->route('psychologist.home')->with('success', 'Группа удалена.');
    }
}
