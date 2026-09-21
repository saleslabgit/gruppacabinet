<?php

namespace App\Http\Controllers\Psychologist;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\PsychologistDocuments;
use App\Support\PsychologistCabinetPages;
use App\Support\PsychologistPages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $user->load(['educationType', 'documents' => fn ($query) => $query->orderByDesc('id')]);

        return view('psychologist.profile.show', array_merge(PsychologistCabinetPages::layout('Мои данные'), [
            'user' => PsychologistPages::profile($user),
            'documents' => $user->documents, 'empty' => $user->documents->isEmpty(),
            'documentActions' => $user->documents->mapWithKeys(fn (UserDocument $document) => [$document->id => [
                'view' => route('psychologist.documents.view', $document),
                'download' => route('psychologist.documents.download', $document),
            ]])->all(),
        ]));
    }

    public function view(Request $request, string $document, PsychologistDocuments $documents): StreamedResponse
    {
        return $this->respond($request, $document, $documents, 'inline');
    }

    public function download(Request $request, string $document, PsychologistDocuments $documents): StreamedResponse
    {
        return $this->respond($request, $document, $documents, 'attachment');
    }

    private function respond(Request $request, string $documentId, PsychologistDocuments $documents, string $disposition): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $document = $user->documents()->whereKey($documentId)->firstOrFail();
        Gate::authorize('viewOwn', $document);

        return $documents->response($document, $disposition);
    }
}
