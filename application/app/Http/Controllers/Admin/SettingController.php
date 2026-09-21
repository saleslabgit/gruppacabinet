<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingRequest;
use App\Models\Setting;
use App\Services\SettingService;
use App\Support\MoneyFormatter;
use App\Support\PsychologistPages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class SettingController extends Controller
{
    public function index(SettingService $settings): View
    {
        Gate::authorize('manage', Setting::class);
        $values = $settings->values();
        foreach (['placement', 'extension'] as $type) {
            $price = $values[$type.'_price_minor_units'];
            $values[$type.'_price'] = $price === null ? '' : str_replace(' ', '', MoneyFormatter::format($price, ''));
        }

        return view('admin.settings.index', array_merge(PsychologistPages::layout('Настройки'), ['realSettings' => true, 'values' => $values]));
    }

    public function update(SettingRequest $request, SettingService $settings): RedirectResponse
    {
        $settings->update($request->values(), $request->user());

        return redirect()->route('admin.settings.index')->with('success', 'Настройки сохранены.');
    }
}
