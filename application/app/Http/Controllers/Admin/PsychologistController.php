<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PasswordSetupRequest;
use App\Http\Requests\Admin\PsychologistActionRequest;
use App\Http\Requests\Admin\PsychologistRequest;
use App\Models\AuditLog;
use App\Models\DictionaryItem;
use App\Models\User;
use App\Services\PasswordSetupService;
use App\Services\PsychologistActions;
use App\Support\DateTimeFormatter;
use App\Support\PsychologistPages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PsychologistController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', 'in:pending,approved,rejected'], 'free' => ['nullable', 'in:free,paid']]);
        $query = User::query()->where('admin', false);
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($query) use ($search): void {
                $query->whereRaw("CONCAT_WS(' ', last_name, first_name, middle_name) LIKE ?", ['%'.$search.'%']);
                foreach (['last_name', 'first_name', 'middle_name', 'email', 'phone'] as $field) {
                    $query->orWhere($field, 'like', '%'.$search.'%');
                }
            });
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($tariff = $filters['free'] ?? null) {
            $query->where('free', $tariff === 'free');
        }
        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.users.index', array_merge(PsychologistPages::layout('Психологи'), [
            'users' => $paginator->getCollection()->map(fn (User $user) => PsychologistPages::profile($user)),
            'empty' => $paginator->isEmpty(), 'filters' => $filters,
            'pages' => $paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)),
            'currentPage' => $paginator->currentPage(),
        ]));
    }

    public function create(): View
    {
        Gate::authorize('viewAny', User::class);

        return $this->form(new User(['free' => false, 'disabled' => false]), true);
    }

    public function store(PsychologistRequest $request): RedirectResponse
    {
        try {
            $psychologist = User::query()->create(array_merge($request->profileData(), [
                'admin' => false, 'status' => 'pending', 'disabled' => false, 'password' => null,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            $this->emailConflict($exception);
        }

        return redirect()->route('admin.psychologists.show', $psychologist)->with('success', 'Психолог создан.');
    }

    public function show(User $psychologist): View
    {
        Gate::authorize('manage', $psychologist);
        $psychologist->load('educationType')->loadCount('documents');
        $groups = $psychologist->groups()->orderByDesc('created_at')->orderByDesc('id')->paginate(10);
        $history = AuditLog::query()->where('entity_type', 'user')->where('entity_id', $psychologist->id)
            ->with(['actor' => fn ($query) => $query->withTrashed()])->orderBy('created_at')->orderBy('id')->get();

        return view('admin.users.show', array_merge(PsychologistPages::layout('Психолог'), [
            'user' => PsychologistPages::profile($psychologist), 'psychologist' => $psychologist,
            'history' => $history, 'psychologistGroups' => $groups,
        ]));
    }

    public function edit(User $psychologist): View
    {
        Gate::authorize('manage', $psychologist);

        return $this->form($psychologist, false);
    }

    private function form(User $psychologist, bool $creating): View
    {
        $psychologist->load('educationType');
        $options = DictionaryItem::query()->whereHas('dictionary', fn ($query) => $query->where('code', 'education_type'))
            ->where('active', true)->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all();
        if ($psychologist->educationType && ! $psychologist->educationType->active) {
            $options[$psychologist->education_type_id] = $psychologist->educationType->name.' (неактивен)';
        }
        $user = PsychologistPages::profile($psychologist);
        $user['personal_data_consent_at'] = $psychologist->personal_data_consent_at
            ? DateTimeFormatter::format($psychologist->personal_data_consent_at, 'Y-m-d\TH:i') : null;

        return view('admin.users.form', array_merge(PsychologistPages::layout($creating ? 'Создать психолога' : 'Редактировать психолога'), [
            'user' => $user, 'creating' => $creating, 'educationOptions' => ['' => 'Не указан'] + $options,
            'formAction' => $creating ? route('admin.psychologists.store') : route('admin.psychologists.update', $psychologist),
        ]));
    }

    public function update(PsychologistRequest $request, User $psychologist): RedirectResponse
    {
        try {
            $psychologist->update($request->profileData());
        } catch (UniqueConstraintViolationException $exception) {
            $this->emailConflict($exception);
        }

        return redirect()->route('admin.psychologists.show', $psychologist)->with('success', 'Анкета сохранена.');
    }

    private function emailConflict(UniqueConstraintViolationException $exception): never
    {
        if (! str_contains($exception->getMessage(), 'gp_users_active_email_unique')) {
            throw $exception;
        }
        throw ValidationException::withMessages(['email' => 'Этот email уже используется.']);
    }

    public function passwordSetup(PasswordSetupRequest $request, User $psychologist, PasswordSetupService $setup): RedirectResponse
    {
        try {
            $setup->invite($psychologist->id, $request->user());
        } catch (AuthorizationException|ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            Log::error('Password setup resend could not be queued.', ['user_id' => $psychologist->id]);

            return redirect()->route('admin.psychologists.show', $psychologist)->withErrors(['action' => 'Не удалось поставить письмо в очередь. Повторите отправку позже.']);
        }

        return redirect()->route('admin.psychologists.show', $psychologist)->with('success', 'Письмо со ссылкой установки пароля поставлено в очередь.');
    }

    public function action(PsychologistActionRequest $request, User $psychologist, PsychologistActions $actions): RedirectResponse
    {
        $action = [
            'approve' => 'approved', 'reject' => 'rejected', 'enable' => 'enabled',
            'disable' => 'disabled', 'tariff' => 'tariff_changed', 'destroy' => 'deleted',
        ][last(explode('.', $request->route()->getName()))];
        $actions->run($psychologist, $request->user(), $action, $action === 'tariff_changed' ? $request->boolean('free') : null);

        return ($action === 'deleted' ? redirect()->route('admin.psychologists.index') : redirect()->route('admin.psychologists.show', $psychologist))
            ->with('success', 'Изменения сохранены.');
    }
}
