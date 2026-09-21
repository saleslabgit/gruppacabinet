<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DictionaryActionRequest;
use App\Http\Requests\DictionaryRequest;
use App\Models\Dictionary;
use App\Services\DictionaryManagement;
use App\Services\DictionaryUsage;
use App\Support\PsychologistPages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DictionaryController extends Controller
{
    public function index(?Dictionary $dictionary = null): View
    {
        Gate::authorize('manage', Dictionary::class);
        $rows = Dictionary::query()->withCount(['items', 'items as active_items_count' => fn ($q) => $q->where('active', true)])
            ->orderBy('code')->orderBy('id')->paginate(20);

        return view('admin.dictionaries.index', array_merge(PsychologistPages::layout('Справочники'), [
            'realDictionaries' => true, 'dictionaries' => $rows, 'editing' => $dictionary,
            'coreCodes' => DictionaryUsage::CORE_CODES,
            'pages' => $rows->getUrlRange(max(1, $rows->currentPage() - 2), min($rows->lastPage(), $rows->currentPage() + 2)),
            'currentPage' => $rows->currentPage(),
        ]));
    }

    public function store(DictionaryRequest $request): RedirectResponse
    {
        DB::transaction(fn () => Dictionary::query()->create($request->safe()->only(['code', 'name'])));

        return redirect()->route('admin.dictionaries.index')->with('success', 'Справочник создан.');
    }

    public function update(DictionaryRequest $request, Dictionary $dictionary): RedirectResponse
    {
        DB::transaction(fn () => $dictionary->update($request->safe()->only(['name'])));

        return redirect()->route('admin.dictionaries.index')->with('success', 'Название сохранено.');
    }

    public function destroy(DictionaryActionRequest $request, Dictionary $dictionary, DictionaryManagement $management): RedirectResponse
    {
        $management->delete($dictionary);

        return redirect()->route('admin.dictionaries.index')->with('success', 'Справочник удалён.');
    }
}
