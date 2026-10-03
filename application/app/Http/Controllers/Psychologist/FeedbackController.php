<?php

namespace App\Http\Controllers\Psychologist;

use App\Http\Controllers\Controller;
use App\Jobs\SendAdminTelegram;
use App\Support\PsychologistCabinetPages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Throwable;

class FeedbackController extends Controller
{
    public function show()
    {
        return view('psychologist.feedback', PsychologistCabinetPages::layout('Сообщить об ошибке') + [
            'notice' => session()->has('success') ? ['tone' => 'success', 'text' => session('success')] : null,
            'errors' => session('errors') ? array_map(fn ($messages) => $messages[0], session('errors')->getBag('default')->messages()) : [],
        ]);
    }

    public function store(Request $request)
    {
        $request->merge(['message' => is_string($request->input('message')) ? trim($request->input('message')) : $request->input('message')]);
        $data = $request->validate(['message' => ['required', 'string', 'max:2800'], 'attachment' => ['prohibited'], 'attachments' => ['prohibited']],
            ['message.required' => 'Введите сообщение.', 'message.max' => 'Допустимо не более 2800 символов.']);
        if ($request->allFiles() !== []) {
            return back()->withErrors(['message' => 'Отправьте только текст, без вложений.'])->withInput();
        }
        try {
            Bus::dispatch((new SendAdminTelegram('feedback', $request->user()->id, $data['message']))->onConnection('database'));
        } catch (Throwable) {
            return back()->withErrors(['message' => 'Не удалось поставить сообщение в очередь. Попробуйте позже.'])->withInput();
        }

        return redirect()->route('psychologist.feedback')->with('success', 'Сообщение принято и поставлено в очередь отправки.');
    }
}
