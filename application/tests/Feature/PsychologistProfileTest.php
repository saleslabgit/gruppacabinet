<?php

namespace Tests\Feature;

use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Models\Group;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PsychologistProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'http://localhost');
        URL::forceRootUrl('http://localhost');
        $this->owner = User::query()->create([
            'email' => 'owner@example.test', 'password' => 'password',
            'remember_token' => 'private-recaller', 'status' => 'approved', 'admin' => false,
        ]);
        Storage::fake('local');
    }

    private function document(User $owner, string $mime = 'application/pdf'): UserDocument
    {
        $document = $owner->documents()->create([
            'type' => 'diploma', 'path' => 'psychologists/'.$owner->id.'/private-file',
            'original_name' => 'Документ-'.$owner->id.'.pdf', 'mime_type' => $mime, 'size' => 1025,
        ]);
        Storage::disk('local')->put($document->path, 'synthetic-private-content');

        return $document;
    }

    public function test_own_questionnaire_uses_shared_view_safe_fields_and_ignores_user_selection(): void
    {
        $dictionary = Dictionary::query()->create(['code' => 'education_type', 'name' => 'Education']);
        $education = DictionaryItem::query()->create(['dictionary_id' => $dictionary->id, 'code' => 'degree', 'name' => 'Stored education', 'active' => false]);
        $fields = [
            'last_name' => 'Surname', 'first_name' => 'First', 'middle_name' => 'Middle', 'phone' => '+375291234567',
            'other_education' => 'Other education', 'license_number' => 'LICENSE-123',
            'license_expires_at' => '2030-12-01', 'group_leading_experience' => 'Stored experience',
            'groups_conducted_count' => 19, 'personal_data_consent_version' => 'consent-v3',
        ];
        $this->owner->update($fields + [
            'education_type_id' => $education->id, 'documents_confirmed' => true, 'education_confirmed' => false,
            'live_session_ready' => null, 'personal_data_consent_at' => '2026-09-21 09:30:00',
        ]);
        $this->owner->trainings()->create(['position' => 1, 'modality_program' => 'Second program', 'training_hours' => 678]);
        $this->owner->trainings()->create(['position' => 0, 'modality_program' => 'First program', 'training_center' => 'Center', 'graduation_year' => 2011]);
        $other = User::query()->create(['email' => 'other@example.test', 'first_name' => 'OtherPrivateName']);
        $ownDocument = $this->document($this->owner);
        $otherDocument = $this->document($other);
        $response = $this->actingAs($this->owner)->get('/profile?user_id='.$other->id.'&id='.$other->id)
            ->assertOk()->assertViewIs('psychologist.profile.show')->assertSee($this->owner->email)
            ->assertSeeInOrder(['First program', 'Second program'])->assertSee('Center')->assertSee('2011')->assertSee('678')
            ->assertSee('Stored education')->assertSee('21.09.2026 12:30')->assertSee('Да')->assertSee('Нет')->assertSee('Не указано')
            ->assertSee($ownDocument->original_name)->assertSee('2 КБ')->assertSee('Диплом')
            ->assertSee(route('psychologist.documents.view', $ownDocument), false)
            ->assertSee(route('psychologist.documents.download', $ownDocument), false);
        foreach ($fields as $value) {
            $response->assertSee((string) $value);
        }
        foreach ([$other->email, $other->first_name, $otherDocument->original_name, $ownDocument->path, $this->owner->password,
            'private-recaller', 'active_email', session()->getId(), '/storage/', 'storage/app/private',
            'Редактировать', 'Загрузить', 'Удалить', 'type="file"', 'data-noop', '_prototype', '/admin/'] as $hidden) {
            $response->assertDontSee($hidden, false);
        }
        $this->assertSame(['educationType', 'trainings', 'documents'], array_keys(Auth::user()->getRelations()));
        foreach (['password', 'remember_token', 'active_email'] as $key) {
            $this->assertArrayNotHasKey($key, $response->viewData('user'));
        }
        $this->get('/profile/'.$other->id)->assertNotFound();
    }

    public function test_nullable_profile_and_approved_empty_documents_state(): void
    {
        $this->actingAs($this->owner)->get('/profile')->assertOk()->assertSee('Не указано')
            ->assertSee('Документов пока нет')->assertDontSee('Просмотр')->assertDontSee('Скачать');
    }

    public function test_navigation_logout_and_profile_does_not_query_groups(): void
    {
        Group::query()->create(['owner_id' => $this->owner->id, 'title' => 'Hidden existing group']);
        $this->actingAs($this->owner);
        foreach (['/' => 'psychologist.home', '/profile' => 'psychologist.profile'] as $path => $current) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->get($path)->assertOk()->assertSee('Мои группы')->assertSee('Мои данные')
                ->assertSee(route('psychologist.home'), false)->assertSee(route('psychologist.profile'), false)
                ->assertSee('action="'.route('logout').'"', false)->assertSee('method="POST"', false)
                ->assertSee('name="_token"', false)->assertDontSee('_prototype');
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            if ($path === '/profile') {
                $response->assertDontSee('Hidden existing group');
                foreach ($queries as $query) {
                    $this->assertStringNotContainsString('gp_groups', $query['query']);
                }
            }
            $this->assertMatchesRegularExpression('/href="'.preg_quote(route($current), '/').'"\s+aria-current="page"/', $response->getContent());
            preg_match('/<nav\b[^>]*>.*?<\/nav>/s', $response->getContent(), $navigation);
            $this->assertSame(1, substr_count($navigation[0], 'aria-current="page"'));
            if ($path === '/') {
                $response->assertViewIs('psychologist.groups.index')->assertViewHas('empty', false)
                    ->assertViewHas('canCreateGroup', true)->assertSee('Hidden existing group');
            }
        }
        $this->post('/logout')->assertRedirect(route('login'));
        $this->get('/profile')->assertRedirect(route('login'));
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->getName() ?? '', 'psychologist.') && ! str_starts_with($route->getName(), 'psychologist.groups.') && ! str_starts_with($route->getName(), 'psychologist.payments.')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods());
                $this->assertContains($route->uri(), ['/', 'profile', 'profile/documents/{document}/view', 'profile/documents/{document}/download']);
            }
        }
    }

    public static function mimeTypes(): array
    {
        return [['application/pdf'], ['image/jpeg'], ['image/png']];
    }

    #[DataProvider('mimeTypes')]
    public function test_owner_streams_use_safe_disposition_mime_and_private_headers(string $mime): void
    {
        $document = $this->document($this->owner, $mime);
        $document->update(['original_name' => "..\\folder\\Документ.pdf\r\n"]);
        $this->actingAs($this->owner);
        foreach (['view' => 'inline', 'download' => 'attachment'] as $action => $disposition) {
            $response = $this->get('/profile/documents/'.$document->id.'/'.$action)->assertOk()
                ->assertHeader('Content-Type', $mime)->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
                ->assertStreamedContent('synthetic-private-content');
            $headers = $response->headers;
            $this->assertStringStartsWith($disposition.';', $headers->get('Content-Disposition'));
            $this->assertStringContainsString(rawurlencode('Документ.pdf'), $headers->get('Content-Disposition'));
            $this->assertTrue($headers->hasCacheControlDirective('private'));
            $this->assertTrue($headers->hasCacheControlDirective('no-store'));
            foreach ([$document->path, 'folder', 'storage/app/private', '/storage/'] as $hidden) {
                $this->assertStringNotContainsString($hidden, (string) $headers);
            }
        }
    }

    public function test_idor_missing_files_and_disallowed_mime_return_no_document_content_or_metadata(): void
    {
        $other = User::query()->create(['email' => 'other@example.test', 'status' => 'approved']);
        $foreign = $this->document($other);
        $own = $this->document($this->owner);
        $this->actingAs($this->owner);
        foreach (['view', 'download'] as $action) {
            $this->get('/profile/documents/'.$foreign->id.'/'.$action)->assertNotFound()
                ->assertDontSee('synthetic-private-content')->assertDontSee($foreign->original_name)->assertDontSee($foreign->path);
        }
        Storage::disk('local')->delete($own->path);
        foreach (['view', 'download'] as $action) {
            $this->get('/profile/documents/'.$own->id.'/'.$action)->assertNotFound();
        }
        Storage::disk('local')->put($own->path, 'synthetic-private-content');
        $own->update(['mime_type' => 'text/html']);
        foreach (['view', 'download'] as $action) {
            $this->get('/profile/documents/'.$own->id.'/'.$action)->assertNotFound()->assertDontSee('synthetic-private-content');
        }
    }

    public function test_guest_and_role_boundaries(): void
    {
        $document = $this->document($this->owner);
        $paths = ['/profile', '/profile/documents/'.$document->id.'/view', '/profile/documents/'.$document->id.'/download'];
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
        $admin = User::query()->create(['email' => 'admin@example.test', 'status' => 'approved', 'admin' => true]);
        $this->actingAs($admin);
        foreach ($paths as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->assertFalse($admin->can('viewOwn', $document));
        $this->actingAs($this->owner);
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/psychologists')->assertForbidden();
        $this->get('/admin/psychologists/'.$this->owner->id.'/documents/'.$document->id.'/view')->assertForbidden();
        $this->assertTrue($this->owner->can('viewOwn', $document));
    }

    public static function revokedAccess(): array
    {
        $cases = [];
        foreach (['disabled', 'pending', 'rejected', 'deleted'] as $state) {
            foreach (['profile', 'view', 'download'] as $endpoint) {
                $cases[$state.'-'.$endpoint] = [$state, $endpoint];
            }
        }

        return $cases;
    }

    #[DataProvider('revokedAccess')]
    public function test_existing_session_loses_profile_and_document_access(string $state, string $endpoint): void
    {
        config()->set('session.driver', 'database');
        $document = $this->document($this->owner);
        $this->post('/login', ['email' => $this->owner->email, 'password' => 'password'])->assertRedirect();
        $id = session()->getId();
        $this->withCookie(config('session.cookie'), $id);
        if ($state === 'deleted') {
            $this->owner->delete();
        } else {
            $this->owner->update($state === 'disabled' ? ['disabled' => true] : ['status' => $state]);
        }
        $this->assertFalse($this->owner->can('viewOwn', $document));
        Auth::forgetGuards();
        $path = $endpoint === 'profile' ? '/profile' : '/profile/documents/'.$document->id.'/'.$endpoint;
        $this->get($path)->assertRedirect(route('login'))->assertSessionHas('access_revoked');
        $this->assertGuest();
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
    }
}
