<?php

namespace App\Http\Controllers\Psychologist;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApplicationIndexRequest;
use App\Models\Group;
use App\Services\ApplicationWorkflow;
use App\Support\ApplicationPages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ApplicationController extends Controller
{
    public function index(ApplicationIndexRequest $request, string $group): View
    {
        $model = Group::query()->where('owner_id', $request->user()->id)->withCount(Group::applicationCounts())->findOrFail($group);
        Gate::authorize('view', $model);
        $filters = $request->validated();
        $query = $model->applications();
        if (($filters['processed'] ?? null) === 'new') {
            $query->whereNull('processed_at');
        } elseif (($filters['processed'] ?? null) === 'processed') {
            $query->whereNotNull('processed_at');
        }
        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();
        $paginator->getCollection()->each(fn ($application) => $application->setRelation('group', $model));

        return view('psychologist.applications.index', ApplicationPages::listing($paginator, false, $filters, $model));
    }

    public function show(Request $request, string $group, string $application): View
    {
        $model = Group::query()->where('owner_id', $request->user()->id)->findOrFail($group);
        $record = $model->applications()->findOrFail($application);
        $record->setRelation('group', $model);
        Gate::authorize('view', $record);

        return view('psychologist.applications.show', ApplicationPages::layout(false, $model) + ['application' => ApplicationPages::data($record, false)]);
    }

    public function action(Request $request, string $group, string $application, ApplicationWorkflow $workflow): RedirectResponse
    {
        $workflow->setProcessed($group, $application, $request->user(), $request->routeIs('*.processed'));

        return redirect()->route('psychologist.groups.applications.show', compact('group', 'application'))->with('success', 'Состояние заявки сохранено.');
    }
}
