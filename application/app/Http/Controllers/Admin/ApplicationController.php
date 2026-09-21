<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApplicationIndexRequest;
use App\Models\GroupApplication;
use App\Support\ApplicationPages;
use App\Support\PhoneNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;

class ApplicationController extends Controller
{
    public function index(ApplicationIndexRequest $request, PhoneNormalizer $phones): View
    {
        Gate::authorize('viewAny', GroupApplication::class);
        $filters = $request->validated();
        $query = GroupApplication::query()->with(['group' => fn ($q) => $q->withTrashed(), 'group.owner' => fn ($q) => $q->withTrashed()]);
        $search = $filters['search'] ?? '';
        if ($search !== '') {
            $digits = $phones->digitsForSearch($search);
            $query->where(function ($q) use ($search, $digits): void {
                $like = '%'.$search.'%';
                $q->whereRaw("CONCAT_WS(' ', last_name, first_name) LIKE ?", [$like])->orWhere('phone', 'like', $like)->orWhere('phone_normalized', 'like', $like);
                if ($digits !== '') {
                    $q->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                }
                $q->orWhereHas('group', fn ($group) => $group->withoutGlobalScope(SoftDeletingScope::class)->where(function ($group) use ($like): void {
                    $group->where('title', 'like', $like)->orWhereHas('owner', fn ($owner) => $owner->withoutGlobalScope(SoftDeletingScope::class)->where(function ($owner) use ($like): void {
                        $owner->whereRaw("CONCAT_WS(' ', last_name, first_name, middle_name) LIKE ?", [$like])->orWhere('email', 'like', $like);
                    }));
                }));
            });
        }
        if (($filters['processed'] ?? null) === 'new') {
            $query->whereNull('processed_at');
        } elseif (($filters['processed'] ?? null) === 'processed') {
            $query->whereNotNull('processed_at');
        }
        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.applications.index', ApplicationPages::listing($paginator, true, $filters));
    }

    public function show(GroupApplication $application): View
    {
        Gate::authorize('view', $application);
        $application->load(['group' => fn ($q) => $q->withTrashed(), 'group.owner' => fn ($q) => $q->withTrashed()]);

        return view('admin.applications.show', ApplicationPages::layout(true) + ['application' => ApplicationPages::data($application, true)]);
    }
}
