<?php

namespace Tests\Feature;

use App\Integration\IntakeService;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Models\Group;
use App\Models\GroupApplication;
use App\Models\IntegrationRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\PsychologistDocuments;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationIntakeTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    private Group $group;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['expiry_warning_days' => 3, 'expired_extension_window_days' => 30] as $key => $value) {
            Setting::create(['key' => $key, 'type' => 'integer', 'value' => (string) $value]);
        }
        URL::forceRootUrl('http://localhost');
        config(['integration.allowed_ips' => [], 'integration.rate_per_minute' => 10000]);
        Storage::fake('local');
        Mail::fake();
        Queue::fake();
        $this->owner = User::create(['email' => 'owner@example.test', 'status' => 'approved']);
        $this->group = Group::create(['owner_id' => $this->owner->id, 'status' => 'active', 'title' => 'Synthetic group']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function fields(): array
    {
        return ['group_uuid' => $this->group->public_uuid, 'last_name' => 'Synthetic', 'first_name' => 'Participant', 'phone' => '+1 (202) 555-0100'];
    }

    private function questionnaire(): array
    {
        return ['email' => 'synthetic@example.test', 'personal_data_consent_at' => '2026-09-21T12:00:00Z', 'personal_data_consent_version' => 'synthetic-v1'];
    }

    private function headers(string $id): array
    {
        return ['HTTP_X_REQUEST_ID' => $id];
    }

    private function application(?array $fields = null, ?string $id = null, array $overrides = [])
    {
        $body = json_encode($fields ?? $this->fields());
        $headers = array_replace($this->headers($id ?? (string) Str::uuid()), $overrides);
        $headers = array_filter($headers, fn ($value) => $value !== null);

        return $this->call('POST', '/api/v1/group-applications', [], [], [], ['CONTENT_TYPE' => 'application/json'] + $headers, $body);
    }

    private function file(string $contents = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'intake-test-');
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, "../synthetic\x01.pdf", 'text/plain', null, true);
    }

    private function psychologist(?array $questionnaire = null, array $files = [], ?string $id = null, array $extra = [])
    {
        $payload = ' '.json_encode($questionnaire ?? $this->questionnaire())."\n";

        return $this->call('POST', '/api/v1/psychologists', ['payload' => $payload] + $extra, [], $files, ['CONTENT_TYPE' => 'multipart/form-data; boundary='.Str::random(20)] + $this->headers($id ?? (string) Str::uuid()));
    }

    public function test_application_replay_ui_counters_and_no_side_effects(): void
    {
        $before = $this->group->fresh()->getAttributes();
        $first = $this->application(id: 'application-one')->assertCreated();
        $second = $this->application(id: 'application-one')->assertCreated();
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertDatabaseCount('gp_group_applications', 1);
        $record = GroupApplication::sole();
        $this->assertSame($this->group->id, $record->group_id);
        $this->assertSame('+12025550100', $record->phone_normalized);
        $this->assertNull($record->processed_at);
        $journal = IntegrationRequest::sole();
        $this->assertNotNull($journal->completed_at);
        $this->assertSame($first->getContent(), $journal->response_body);
        $this->assertStringNotContainsString('Synthetic', $journal->toJson());
        $this->actingAs($this->owner)->get('/groups/'.$this->group->id.'/applications')->assertOk()->assertSee('Participant');
        $this->get('/groups/'.$this->group->id.'/applications/'.$record->id)->assertOk()->assertSee($record->phone);
        $this->get('/')->assertOk()->assertViewHas('groups', fn ($groups) => $groups->first()['new_count'] === 1);
        $admin = User::create(['email' => 'admin@example.test', 'status' => 'approved', 'admin' => true]);
        $this->actingAs($admin)->get('/admin/applications')->assertOk()->assertSee('Participant');
        $other = User::create(['email' => 'other@example.test', 'status' => 'approved']);
        $this->actingAs($other)->get('/groups/'.$this->group->id.'/applications/'.$record->id)->assertNotFound();
        $this->assertSame($before, $this->group->fresh()->getAttributes());
        foreach (['gp_payments', 'gp_group_status_history', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_idempotency_conflicts_semantic_normalization_and_distinct_ids(): void
    {
        $this->application(id: 'one')->assertCreated();
        $fields = $this->fields();
        $fields['phone'] = '0012025550100';
        $this->application($fields, 'one')->assertCreated();
        $fields['first_name'] = 'Changed';
        $this->application($fields, 'one')->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->psychologist(id: 'one')->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->application(id: 'two')->assertCreated();
        $this->application(id: 'ONE')->assertCreated();
        $this->assertDatabaseCount('gp_group_applications', 3);
    }

    public function test_public_protocol_requires_only_request_id_and_ignores_legacy_headers(): void
    {
        $this->assertArrayNotHasKey('secret', config('integration'));
        $this->assertArrayNotHasKey('timestamp_tolerance', config('integration'));
        $this->application()->assertCreated();
        $this->psychologist()->assertCreated();
        $this->application(overrides: ['HTTP_X_TIMESTAMP' => 'invalid', 'HTTP_X_SIGNATURE' => 'invalid'])->assertCreated();
        foreach ([[null, 'missing_request_id'], ['bad id', 'invalid_request_id'], ['.bad', 'invalid_request_id'], [str_repeat('x', 129), 'invalid_request_id']] as [$id, $code]) {
            $this->application(overrides: ['HTTP_X_REQUEST_ID' => $id])->assertStatus(400)->assertJsonPath('code', $code)->assertHeader('Content-Type', 'application/json');
            $this->call('POST', '/api/v1/psychologists', ['payload' => json_encode($this->questionnaire())], [], [],
                ['CONTENT_TYPE' => 'multipart/form-data'] + ($id === null ? [] : $this->headers($id)))
                ->assertStatus(400)->assertJsonPath('code', $code);
        }
        foreach (['_', '-', 'A.b:C-0_1', str_repeat('x', 128)] as $id) {
            $this->application(id: $id)->assertCreated();
        }
    }

    public function test_ip_allowlist_limiter_and_json_routing_errors(): void
    {
        config(['integration.allowed_ips' => ['192.0.2.1']]);
        $this->application()->assertForbidden()->assertJsonPath('code', 'source_not_allowed');
        config(['integration.allowed_ips' => ['127.0.0.1']]);
        $this->application()->assertCreated();
        config(['integration.rate_per_minute' => 1]);
        $this->application()->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->psychologist()->assertCreated();
        $this->psychologist()->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->get('/api/v1/unknown')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->get('/api/v1/group-applications')->assertStatus(405)->assertJsonPath('code', 'method_not_allowed');
        foreach (['api/v1/group-applications', 'api/v1/psychologists'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === $uri);
            $this->assertContains('api', $route->gatherMiddleware());
            $this->assertNotContains('web', $route->gatherMiddleware());
            $this->assertNotContains('auth', $route->gatherMiddleware());
        }
    }

    public function test_group_rejections_and_protected_fields(): void
    {
        foreach (['draft', 'moderation', 'revision', 'rejected', 'approved', 'expired', 'awaiting_payment'] as $status) {
            $this->group->update(['status' => $status]);
            $this->application()->assertStatus(422)->assertJsonPath('code', 'group_not_accepting_applications');
        }
        $this->group->update(['status' => 'active', 'disabled' => true]);
        $this->application()->assertStatus(422);
        $this->group->delete();
        $this->application()->assertNotFound()->assertJsonPath('code', 'group_not_found');
        $this->application(array_replace($this->fields(), ['group_uuid' => (string) Str::uuid()]))->assertNotFound();
        foreach (['psychologist_id', 'owner_id', 'group_id', 'processed_at', 'id', 'status'] as $field) {
            $this->application($this->fields() + [$field => 1])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
        foreach (['phone' => '02025550100', 'first_name' => '', 'group_uuid' => '1'] as $field => $value) {
            $this->application(array_replace($this->fields(), [$field => $value]))->assertStatus(422);
        }
        $this->assertDatabaseCount('gp_group_applications', 0);
        $this->assertDatabaseCount('gp_integration_requests', 0);
    }

    public function test_new_psychologist_private_files_and_boundary_independent_replay(): void
    {
        $files = ['diploma' => $this->file(), 'certificate_0' => $this->file()];
        $questionnaire = $this->questionnaire();
        $questionnaire['email'] = '  SYNTHETIC@example.test  ';
        $first = $this->psychologist($questionnaire, $files, 'psych-one')->assertCreated()->assertJsonPath('data.status', 'pending');
        $second = $this->psychologist($questionnaire, $files, 'psych-one')->assertCreated();
        $this->assertSame($first->getContent(), $second->getContent());
        $user = User::where('email', 'synthetic@example.test')->sole();
        $this->assertSame('pending', $user->status->value);
        foreach (['free', 'disabled', 'admin'] as $field) {
            $this->assertFalse($user->$field);
        }
        $this->assertNull($user->password);
        $this->assertSame('2026-09-21 12:00:00', $user->personal_data_consent_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('gp_user_documents', 2);
        foreach ($user->documents as $document) {
            $this->assertSame('synthetic.pdf', $document->original_name);
            $this->assertSame('application/pdf', $document->mime_type);
            $this->assertStringNotContainsString('synthetic', $document->path);
            Storage::disk('local')->assertExists($document->path);
            Storage::disk('public')->assertMissing($document->path);
        }
        $admin = User::create(['email' => 'admin@example.test', 'status' => 'approved', 'admin' => true]);
        $this->actingAs($admin)->get('/admin/psychologists?status=pending')->assertOk()->assertSee('synthetic@example.test');
        $doc = $user->documents->first();
        $this->get('/admin/psychologists/'.$user->id.'/documents/'.$doc->id.'/view')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringNotContainsString('synthetic@example.test', IntegrationRequest::sole()->toJson());
        $this->assertStringNotContainsString('synthetic.pdf', IntegrationRequest::sole()->toJson());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    public function test_repeat_matrix_preserves_internal_fields_and_appends_documents(): void
    {
        $this->psychologist()->assertCreated();
        $user = User::where('email', 'synthetic@example.test')->sole();
        $user->update(['free' => true, 'password' => 'synthetic-password', 'remember_token' => 'synthetic-token']);
        $password = $user->password;
        foreach (['pending', 'rejected'] as $status) {
            $user->update(['status' => $status]);
            $this->psychologist($this->questionnaire() + ['last_name' => 'Updated'], ['diploma' => $this->file()])->assertOk();
            $user->refresh();
            $this->assertSame('pending', $user->status->value);
            $this->assertTrue($user->free);
            $this->assertFalse($user->disabled);
            $this->assertFalse($user->admin);
            $this->assertSame($password, $user->password);
            $this->assertSame('synthetic-token', $user->remember_token);
        }
        $this->assertDatabaseCount('gp_user_documents', 2);
        foreach (['approved', 'disabled', 'deleted'] as $state) {
            if ($state === 'approved') {
                $user->update(['status' => 'approved']);
            } elseif ($state === 'disabled') {
                $user->update(['status' => 'pending', 'disabled' => true]);
            } else {
                $user->update(['disabled' => false]);
                $user->delete();
            }
            $before = $user->fresh()->getAttributes();
            $this->psychologist(files: ['diploma' => $this->file()])->assertStatus(409)->assertJsonPath('code', 'psychologist_conflict');
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->assertDatabaseCount('gp_user_documents', 2);
        }
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_repeat_preserves_an_existing_admin_flag_without_granting_access(): void
    {
        $user = User::create(['email' => 'synthetic@example.test', 'status' => 'pending', 'admin' => true, 'free' => true]);
        $this->psychologist()->assertOk();
        $user->refresh();
        $this->assertTrue($user->admin);
        $this->assertTrue($user->free);
        $this->assertSame('pending', $user->status->value);
        $this->assertNull($user->password);
    }

    public function test_questionnaire_validation_full_submission_and_education_codes(): void
    {
        foreach (['id', 'status', 'accept', 'disabled', 'free', 'admin', 'password', 'remember_token', 'deleted_at', 'education_type_id'] as $field) {
            $this->psychologist($this->questionnaire() + [$field => 1])->assertStatus(422);
        }
        foreach (['email', 'personal_data_consent_at', 'personal_data_consent_version'] as $field) {
            $questionnaire = $this->questionnaire();
            unset($questionnaire[$field]);
            $this->psychologist($questionnaire)->assertStatus(422);
        }
        $dictionary = Dictionary::create(['code' => 'education_type', 'name' => 'Synthetic education']);
        $item = DictionaryItem::create(['dictionary_id' => $dictionary->id, 'code' => 'synthetic', 'name' => 'Synthetic', 'active' => false]);
        $questionnaire = $this->questionnaire() + ['education_type_code' => 'synthetic', 'first_name' => 'Optional'];
        $this->psychologist($questionnaire)->assertStatus(422);
        $this->psychologist($this->questionnaire() + ['education_type_code' => 'unknown'])->assertStatus(422);
        $other = Dictionary::create(['code' => 'other', 'name' => 'Other']);
        DictionaryItem::create(['dictionary_id' => $other->id, 'code' => 'wrong', 'name' => 'Wrong']);
        $this->psychologist($this->questionnaire() + ['education_type_code' => 'wrong'])->assertStatus(422);
        $item->update(['active' => true]);
        $this->psychologist($questionnaire)->assertCreated();
        $user = User::where('email', 'synthetic@example.test')->sole();
        $this->assertSame($item->id, $user->education_type_id);
        $item->update(['active' => false]);
        $this->psychologist($questionnaire)->assertOk();
        $this->psychologist()->assertOk();
        $this->assertNull($user->fresh()->first_name);
        $this->assertNull($user->fresh()->education_type_id);
    }

    public function test_multipart_rejects_invalid_fields_arrays_and_legacy_manifest(): void
    {
        foreach (['unknown', 'document_0', 'certificate', 'certificate_-1', 'certificate_01', 'certificate_1x', 'certificate_1.0', 'certificate_'] as $field) {
            $this->psychologist(files: [$field => $this->file()])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
        foreach (['diploma', 'certificate_0', 'license', 'registration'] as $field) {
            $this->psychologist(files: [$field => [$this->file(), $this->file()]])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
        $this->psychologist(extra: ['email' => 'extra@example.test'])->assertStatus(422);
        $this->psychologist(['questionnaire' => $this->questionnaire(), 'documents' => []])->assertStatus(422);
        $this->psychologist($this->questionnaire() + ['documents' => []])->assertStatus(422);
        $failed = new UploadedFile($this->file()->getPathname(), 'failed.pdf', 'application/pdf', UPLOAD_ERR_PARTIAL, true);
        $this->psychologist(files: ['diploma' => $failed])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertDatabaseCount('gp_user_documents', 0);
        $this->assertDatabaseCount('gp_integration_requests', 0);
    }

    public function test_document_content_size_types_and_validation(): void
    {
        foreach (PsychologistDocumentTest::allowedFiles() as [$name, $bytes, $mime]) {
            foreach (['diploma', 'certificate_0', 'certificate_1', 'license', 'registration'] as $field) {
                $this->psychologist(files: [$field => $this->file($bytes)])->assertSuccessful();
                $document = User::where('email', 'synthetic@example.test')->sole()->documents()->latest('id')->firstOrFail();
                $this->assertSame(str_starts_with($field, 'certificate_') ? 'certificate' : $field, $document->type);
                $this->assertSame($mime, $document->mime_type);
                $this->assertSame(strlen($bytes), $document->size);
            }
        }
        $this->psychologist(files: ['diploma' => $this->file('<?php echo "bad";')])->assertStatus(422);
        config(['psychologist_documents.max_kb' => 1]);
        $this->psychologist(files: ['diploma' => $this->file("%PDF-1.4\n".str_repeat(' ', 2048))])->assertStatus(422);
    }

    public function test_multiple_certificates_and_server_observed_file_fingerprint(): void
    {
        $files = ['certificate_0' => $this->file(), 'certificate_1' => $this->file("%PDF-1.4\nsecond")];
        $first = $this->psychologist(files: $files, id: 'certificates')->assertCreated();
        $replay = $this->psychologist(files: ['certificate_8' => $files['certificate_1'], 'certificate_3' => $files['certificate_0']], id: 'certificates')->assertCreated();
        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertDatabaseCount('gp_user_documents', 2);
        $files['certificate_1'] = $this->file("%PDF-1.4\nchange");
        $this->psychologist(files: $files, id: 'certificates')->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('gp_user_documents', 2);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $journal = IntegrationRequest::sole()->toJson();
        foreach (['synthetic@example.test', 'synthetic.pdf', '%PDF', 'personal_data_consent'] as $private) {
            $this->assertStringNotContainsString($private, $journal);
        }
    }

    public function test_rollback_after_multiple_files_and_journal_failure_allows_retry(): void
    {
        IntegrationRequest::updating(function () {
            throw new \RuntimeException('Synthetic failure after uploads');
        });
        try {
            $this->psychologist(files: ['diploma' => $this->file(), 'certificate_0' => $this->file()], id: 'retry')->assertStatus(500)->assertJsonPath('code', 'internal_error');
        } finally {
            IntegrationRequest::flushEventListeners();
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('gp_user_documents', 0);
        $this->assertDatabaseCount('gp_integration_requests', 0);
        $this->assertDatabaseMissing('gp_users', ['email' => 'synthetic@example.test']);
        $this->psychologist(id: 'retry')->assertCreated();
    }

    public function test_second_upload_failure_cleans_first_file(): void
    {
        $real = new PsychologistDocuments;
        $mock = \Mockery::mock(PsychologistDocuments::class)->makePartial();
        $calls = 0;
        $mock->shouldReceive('upload')->andReturnUsing(function (...$args) use ($real, &$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('Synthetic storage failure');
            }

            return $real->upload(...$args);
        });
        $this->app->instance(PsychologistDocuments::class, $mock);
        $this->psychologist(files: ['diploma' => $this->file(), 'certificate_0' => $this->file()])->assertStatus(500);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('gp_user_documents', 0);
        $this->assertDatabaseCount('gp_integration_requests', 0);
    }

    public function test_partial_storage_write_is_removed_and_prior_file_is_rolled_back(): void
    {
        $disk = Storage::disk('local');
        $failing = \Mockery::mock(FilesystemAdapter::class);
        $calls = 0;
        $failing->shouldReceive('putFileAs')->andReturnUsing(function ($directory, $file, $name) use ($disk, &$calls) {
            $path = $disk->putFileAs($directory, $file, $name);

            return ++$calls === 2 ? false : $path;
        });
        $failing->shouldReceive('exists')->andReturnUsing(fn ($path) => $disk->exists($path));
        $failing->shouldReceive('delete')->andReturnUsing(fn ($path) => $disk->delete($path));
        Storage::shouldReceive('disk')->with('local')->andReturn($failing);
        $this->psychologist(files: ['diploma' => $this->file(), 'certificate_0' => $this->file()])->assertStatus(500);
        $this->assertSame([], $disk->allFiles());
        $this->assertDatabaseCount('gp_user_documents', 0);
        $this->assertDatabaseCount('gp_integration_requests', 0);
    }

    public function test_completed_replay_does_not_depend_on_later_business_state(): void
    {
        $first = $this->application(id: 'accepted-before-change')->assertCreated();
        $this->group->update(['disabled' => true]);
        $replay = $this->application(id: 'accepted-before-change')->assertCreated();
        $this->assertSame($first->getContent(), $replay->getContent());
        $first = $this->psychologist(id: 'questionnaire-before-change')->assertCreated();
        User::where('email', 'synthetic@example.test')->firstOrFail()->update(['status' => 'approved']);
        $replay = $this->psychologist(id: 'questionnaire-before-change')->assertCreated();
        $this->assertSame($first->getContent(), $replay->getContent());
    }

    public function test_malformed_payloads_and_media_types_are_safe_json(): void
    {
        foreach (['{', '[]', 'null'] as $body) {
            $this->call('POST', '/api/v1/group-applications', [], [], [], ['CONTENT_TYPE' => 'application/json'] + $this->headers('malformed'), $body)
                ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
        foreach ([[], ['payload' => ['nested']], ['payload' => '{'], ['payload' => '[]'], ['payload' => 'null']] as $form) {
            $this->call('POST', '/api/v1/psychologists', $form, [], [], ['CONTENT_TYPE' => 'multipart/form-data'] + $this->headers('malformed-psychologist'))
                ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
        foreach (['psychologists', 'group-applications'] as $endpoint) {
            foreach (['text/plain', $endpoint === 'psychologists' ? 'application/json' : 'multipart/form-data'] as $type) {
                $this->call('POST', '/api/v1/'.$endpoint, [], [], [], ['CONTENT_TYPE' => $type] + $this->headers('wrong-media'), '{}')
                    ->assertStatus(415)->assertJsonPath('code', 'unsupported_media_type');
            }
            $multipart = $endpoint === 'psychologists';
            $body = json_encode($multipart ? $this->questionnaire() : $this->fields());
            $this->call('POST', '/api/v1/'.$endpoint.'?owner_id=1', $multipart ? ['payload' => $body] : [], [], [],
                ['CONTENT_TYPE' => $multipart ? 'multipart/form-data' : 'application/json'] + $this->headers('query'), $multipart ? null : $body)
                ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
    }

    public function test_safe_logging_including_unexpected_database_exception(): void
    {
        Log::spy();
        $this->application($this->fields() + ['unexpected' => 'Synthetic'])->assertStatus(422);
        $this->mock(IntakeService::class, function ($mock) {
            $mock->shouldReceive('submit')->andThrow(new QueryException(
                'mysql', 'insert into synthetic values (?, ?)', ['synthetic@example.test', '+12025550100'],
                new \PDOException('Sensitive synthetic SQL failure'),
            ));
        });
        $this->application()->assertStatus(500)->assertDontSee('Sensitive')->assertDontSee('synthetic@example.test');
        Log::shouldHaveReceived('warning')->twice()->withArgs(function ($message, $context) {
            $encoded = json_encode([$message, $context]);
            foreach (['Synthetic', 'Participant', 'synthetic@example.test', '+12025550100', str_repeat('0', 64), 'Sensitive'] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $encoded);
            }
            $this->assertArrayHasKey('exception_class', $context);

            return true;
        });
        Log::shouldNotHaveReceived('error');
    }
}
