<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DocumentRequest;
use App\Http\Requests\Admin\PsychologistActionRequest;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\PsychologistDocuments;
use App\Support\PsychologistPages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PsychologistDocumentController extends Controller
{
    public function index(User $psychologist): View
    {
        Gate::authorize('manage', $psychologist);
        $documents = $psychologist->documents()->orderByDesc('id')->get();

        return view('admin.users.documents', array_merge(PsychologistPages::layout('Документы психолога'), [
            'user' => PsychologistPages::profile($psychologist), 'psychologist' => $psychologist,
            'documents' => $documents, 'empty' => $documents->isEmpty(),
        ]));
    }

    public function store(DocumentRequest $request, User $psychologist, PsychologistDocuments $documents): RedirectResponse
    {
        $documents->upload($psychologist, $request->file('file'), $request->validated('type'));

        return redirect()->route('admin.psychologists.documents.index', $psychologist)->with('success', 'Документ загружен.');
    }

    public function view(User $psychologist, UserDocument $document, PsychologistDocuments $documents): StreamedResponse
    {
        return $this->respond($psychologist, $document, $documents, 'inline');
    }

    public function download(User $psychologist, UserDocument $document, PsychologistDocuments $documents): StreamedResponse
    {
        return $this->respond($psychologist, $document, $documents, 'attachment');
    }

    private function respond(User $psychologist, UserDocument $document, PsychologistDocuments $documents, string $disposition): StreamedResponse
    {
        Gate::authorize('manage', [$document, $psychologist]);
        $disk = Storage::disk(config('psychologist_documents.disk'));
        abort_unless($disk->exists($document->path), 404);
        abort_unless(in_array($document->mime_type, config('psychologist_documents.mime_types'), true), 404);

        return $disk->response($document->path, $documents->safeName($document->original_name), [
            'Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "sandbox; default-src 'none'",
        ], $disposition);
    }

    public function destroy(PsychologistActionRequest $request, User $psychologist, UserDocument $document, PsychologistDocuments $documents): RedirectResponse
    {
        Gate::authorize('manage', [$document, $psychologist]);
        $documents->delete($document);

        return redirect()->route('admin.psychologists.documents.index', $psychologist)->with('success', 'Документ удалён.');
    }
}
