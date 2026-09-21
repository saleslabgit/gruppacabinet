<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDocument;
use App\Services\PsychologistDocuments;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PsychologistDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'http://localhost');
        URL::forceRootUrl('http://localhost');
        $this->admin = User::query()->create(['email' => 'admin@example.test', 'admin' => true, 'status' => 'approved']);
        $this->owner = User::query()->create(['email' => 'owner@example.test', 'admin' => false]);
        $this->actingAs($this->admin);
        Storage::fake('local');
    }

    private array $temporaryFiles = [];

    private function file(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'document-test-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, null, null, true);
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

    public static function allowedFiles(): array
    {
        return [
            ['diploma.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF", 'application/pdf'],
            ['certificate.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOC8AAAAASUVORK5CYII='), 'image/png'],
            ['license.jpg', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k='), 'image/jpeg'],
        ];
    }

    #[DataProvider('allowedFiles')]
    public function test_allowed_actual_contents_private_random_storage_view_download_and_delete(string $name, string $contents, string $mime): void
    {
        $base = '/admin/psychologists/'.$this->owner->id.'/documents';
        $this->post($base, ['type' => 'diploma', 'file' => $this->file($name, $contents)])
            ->assertSessionHasNoErrors()->assertRedirect();
        $document = UserDocument::query()->sole();
        $this->assertSame($name, $document->original_name);
        $this->assertSame($mime, $document->mime_type);
        $this->assertSame(strlen($contents), $document->size);
        $this->assertStringNotContainsString($name, $document->path);
        $this->assertStringStartsWith('psychologists/'.$this->owner->id.'/', $document->path);
        Storage::disk('local')->assertExists($document->path);
        Storage::disk('public')->assertMissing($document->path);
        $this->get($base)->assertOk()->assertSee($name)->assertDontSee('/storage/')->assertDontSee($document->path);
        $this->get($base.'/'.$document->id.'/view')->assertOk()->assertHeader('Content-Type', $mime)
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertStreamedContent($contents);
        $this->get($base.'/'.$document->id.'/download')->assertOk()->assertDownload($name)->assertStreamedContent($contents);
        $this->get('/storage/'.$document->path)->assertNotFound();
        $this->delete($base.'/'.$document->id, ['confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseMissing('gp_user_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->path);
    }

    public function test_content_not_extension_and_maximum_and_business_type_are_validated(): void
    {
        $base = '/admin/psychologists/'.$this->owner->id.'/documents';
        foreach ([['text.pdf', 'plain text'], ['malware.png', '<?php echo "bad";']] as [$name, $contents]) {
            $this->post($base, ['type' => 'diploma', 'file' => $this->file($name, $contents)])
                ->assertSessionHasErrors('file');
        }
        config()->set('psychologist_documents.max_kb', 1);
        $this->post($base, ['type' => 'diploma', 'file' => $this->file('large.pdf', "%PDF-1.4\n".str_repeat(' ', 2048))])->assertSessionHasErrors('file');
        $this->post($base, ['type' => 'unknown', 'file' => $this->file('real.pdf', self::allowedFiles()[0][1])])->assertSessionHasErrors('type');
        $this->assertDatabaseCount('gp_user_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_nested_owner_substitution_admin_targets_and_psychologist_access_are_denied(): void
    {
        $document = app(PsychologistDocuments::class)->upload($this->owner, $this->file('real.pdf', self::allowedFiles()[0][1]), 'diploma');
        $other = User::query()->create(['email' => 'other@example.test', 'admin' => false, 'status' => 'approved']);
        foreach ([$other, $this->admin] as $parent) {
            $base = '/admin/psychologists/'.$parent->id.'/documents/'.$document->id;
            $this->get($base.'/view')->assertForbidden();
            $this->get($base.'/download')->assertForbidden();
            $this->delete($base, ['confirmed' => 1])->assertForbidden();
        }
        $this->actingAs($other);
        $base = '/admin/psychologists/'.$this->owner->id.'/documents/'.$document->id;
        $this->get($base.'/view')->assertForbidden();
        $this->get($base.'/download')->assertForbidden();
        $this->delete($base, ['confirmed' => 1])->assertForbidden();
        $this->actingAs($this->admin);
        $this->owner->delete();
        $this->get($base.'/view')->assertNotFound();
        $this->assertDatabaseHas('gp_user_documents', ['id' => $document->id]);
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_failed_database_persistence_removes_orphan(): void
    {
        $unpersisted = new User;
        $unpersisted->id = 999999;
        try {
            app(PsychologistDocuments::class)->upload($unpersisted, $this->file('real.pdf', self::allowedFiles()[0][1]), 'diploma');
            $this->fail('Expected foreign-key violation');
        } catch (QueryException) {
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_storage_delete_failure_retains_database_row_and_does_not_claim_success(): void
    {
        $document = app(PsychologistDocuments::class)->upload($this->owner, $this->file('real.pdf', self::allowedFiles()[0][1]), 'diploma');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->with($document->path)->andReturn(true);
        $disk->shouldReceive('delete')->with($document->path)->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            app(PsychologistDocuments::class)->delete($document);
            $this->fail('Expected storage failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Private document deletion failed.', $exception->getMessage());
        }
        $this->assertDatabaseHas('gp_user_documents', ['id' => $document->id]);
    }

    public function test_real_private_disk_configuration_and_safe_download_filename(): void
    {
        // Use a unique physical directory under the actual private root, with cleanup even on failure.
        $directory = 'stage5-test-'.Str::uuid();
        $disk = Storage::build(['driver' => 'local', 'root' => storage_path('app/private')]);
        try {
            $disk->put($directory.'/probe.pdf', self::allowedFiles()[0][1]);
            $this->assertFileExists(storage_path('app/private/'.$directory.'/probe.pdf'));
            $this->assertFileDoesNotExist(public_path('storage/'.$directory.'/probe.pdf'));
            $this->get('/storage/'.$directory.'/probe.pdf')->assertNotFound();
            $this->assertFalse(config('filesystems.disks.local.serve'));
        } finally {
            $disk->deleteDirectory($directory);
        }
        $service = app(PsychologistDocuments::class);
        $this->assertSame('file.pdf', $service->safeName("..\\folder\\file.pdf\r\n"));
    }
}
