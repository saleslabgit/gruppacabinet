<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GroupStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\GroupActionRequest;
use App\Http\Requests\GroupIndexRequest;
use App\Http\Requests\GroupRequest;
use App\Models\Group;
use App\Models\User;
use App\Services\GroupWorkflow;
use App\Support\GroupPages;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class GroupController extends Controller
{
    public function index(GroupIndexRequest $request): View
    {
        Gate::authorize('viewAny', Group::class);
        $filters = $request->validated();
        $query = Group::query()->with(['owner' => fn ($q) => $q->withTrashed(), 'format', 'gender']);
        $search = $filters['search'] ?? null;
        if ($search !== null && $search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', '%'.$search.'%');
                if (ctype_digit($search)) {
                    $q->orWhere('id', $search);
                }
                $q->orWhereHas('owner', fn ($owner) => $owner->withoutGlobalScope(SoftDeletingScope::class)->where(function ($owner) use ($search): void {
                    $owner->whereRaw("CONCAT_WS(' ', last_name, first_name, middle_name) LIKE ?", ['%'.$search.'%'])->orWhere('email', 'like', '%'.$search.'%');
                }));
            });
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($free = $filters['free'] ?? null) {
            $query->where('free', $free === 'free');
        }
        if (($filters['quick'] ?? null) === 'approved') {
            $query->where('status', GroupStatus::Approved);
        }
        if (($filters['quick'] ?? null) === 'abandoned') {
            $query->where('status', GroupStatus::Draft)->where('created_at', '<=', now()->subDays(config('groups.abandoned_draft_days')));
        }
        $paginator = $query->orderByDesc($filters['sort'] ?? 'created_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.groups.index', array_merge(GroupPages::layout('Группы', true), [
            'filters' => $filters, 'groups' => $paginator->getCollection()->map(fn (Group $group) => GroupPages::data($group)),
            'empty' => $paginator->isEmpty(),
            'pages' => $paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)),
            'currentPage' => $paginator->currentPage(),
        ]));
    }

    public function create(): View
    {
        Gate::authorize('viewAny', Group::class);

        return view('admin.groups.form', GroupPages::form(new Group, true, true));
    }

    public function store(GroupRequest $request, GroupWorkflow $workflow): RedirectResponse
    {
        $group = $workflow->create(User::query()->findOrFail($request->validated('owner_id')), $request->user(), $request->content());

        return redirect()->route('admin.groups.show', $group)->with('success', 'Группа создана.');
    }

    public function show(Group $group): View
    {
        Gate::authorize('view', $group);

        return view('admin.groups.show', GroupPages::detail($group, true));
    }

    public function edit(Group $group): View
    {
        Gate::authorize('update', $group);

        return view('admin.groups.form', GroupPages::form($group, true));
    }

    public function update(GroupRequest $request, Group $group, GroupWorkflow $workflow): RedirectResponse
    {
        $workflow->save($group, $request->user(), $request->content());

        return redirect()->route('admin.groups.show', $group)->with('success', 'Изменения сохранены.');
    }

    public function action(GroupActionRequest $request, Group $group, GroupWorkflow $workflow): RedirectResponse
    {
        if ($request->routeIs('*.destroy')) {
            $workflow->delete($group, $request->user());

            return redirect()->route('admin.groups.index')->with('success', 'Группа удалена.');
        }
        if ($request->routeIs('*.activate')) {
            $workflow->activate($group, $request->user());
        } else {
            $target = match (true) {
                $request->routeIs('*.approve') => GroupStatus::Approved,
                $request->routeIs('*.revision') => GroupStatus::Revision,
                default => GroupStatus::Rejected,
            };
            $workflow->moderate($group, $request->user(), $target, $request->validated('moderator_comment') ?? $request->validated('rejection_reason'));
        }

        return redirect()->route('admin.groups.show', $group)->with('success', 'Изменения сохранены.');
    }
}
