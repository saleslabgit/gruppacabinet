<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DictionaryActionRequest;
use App\Http\Requests\DictionaryItemRequest;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Services\DictionaryManagement;
use App\Services\DictionaryUsage;
use App\Support\PsychologistPages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DictionaryItemController extends Controller
{
    public function index(Dictionary $dictionary, DictionaryUsage $usage, ?DictionaryItem $item = null): View
    {
        Gate::authorize('manage', Dictionary::class);
        $query = $dictionary->items()->select('gp_dictionary_items.*');
        $references = $usage->references($dictionary);
        if ($references !== null) {
            $query->selectSub(DB::query()->fromSub($references, 'usage')->selectRaw('COUNT(*)')->whereColumn('usage.item_id', 'gp_dictionary_items.id'), 'usage_count');
        } else {
            $query->selectRaw('0 as usage_count');
        }
        $rows = $query->orderBy('sort_order')->orderBy('id')->paginate(20);

        return view('admin.dictionaries.items', array_merge(PsychologistPages::layout('Элементы справочника'), [
            'realDictionaries' => true, 'dictionary' => $dictionary, 'items' => $rows, 'editing' => $item,
            'pages' => $rows->getUrlRange(max(1, $rows->currentPage() - 2), min($rows->lastPage(), $rows->currentPage() + 2)),
            'currentPage' => $rows->currentPage(),
        ]));
    }

    public function store(DictionaryItemRequest $request, Dictionary $dictionary, DictionaryManagement $management): RedirectResponse
    {
        $management->saveItem($dictionary, null, $request->validated());

        return redirect()->route('admin.dictionaries.items.index', $dictionary)->with('success', 'Элемент добавлен.');
    }

    public function update(DictionaryItemRequest $request, Dictionary $dictionary, DictionaryItem $item, DictionaryManagement $management): RedirectResponse
    {
        $management->saveItem($dictionary, $item, $request->validated());

        return redirect()->route('admin.dictionaries.items.index', $dictionary)->with('success', 'Элемент сохранён.');
    }

    public function action(DictionaryActionRequest $request, Dictionary $dictionary, DictionaryItem $item, DictionaryManagement $management): RedirectResponse
    {
        if ($request->routeIs('*.destroy')) {
            $management->deleteItem($dictionary, $item);
            $message = 'Элемент удалён.';
        } else {
            $active = $request->routeIs('*.activate');
            $management->saveItem($dictionary, $item, ['active' => $active, 'confirmed' => true]);
            $message = $active ? 'Элемент активен.' : 'Элемент неактивен.';
        }

        return redirect()->route('admin.dictionaries.items.index', $dictionary)->with('success', $message);
    }
}
