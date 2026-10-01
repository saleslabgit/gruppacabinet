<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use App\Services\GroupCovers;
use App\Services\GroupWorkflow;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GroupContentFixture as Fixture;
use Tests\TestCase;

class GroupCoverTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        URL::forceRootUrl('http://localhost');
        $this->owner = User::create(['email' => 'cover@example.test', 'status' => 'approved', 'free' => true]);
        $this->group = app(GroupWorkflow::class)->create($this->owner, $this->owner);
    }

    public static function images(): array
    {
        return [['png', 'image/png'], ['jpg', 'image/jpeg'], ['webp', 'image/webp']];
    }

    #[DataProvider('images')]
    public function test_real_image_content_private_random_storage_and_authorized_preview(string $extension, string $mime): void
    {
        $file = Fixture::cover($extension);
        $group = app(GroupWorkflow::class)->save($this->group, $this->owner, ['cover' => $file]);
        $this->assertMatchesRegularExpression('~^group-covers/[A-Za-z0-9]{40}\\.'.$extension.'$~', $group->cover_path);
        $this->assertSame($file->getSize(), $group->cover_size);
        $this->assertSame($mime, $group->cover_mime_type);
        $this->assertSame('synthetic.'.$extension, $group->cover_original_name);
        Storage::disk('local')->assertExists($group->cover_path);
        $response = $this->actingAs($this->owner)->get('/groups/'.$group->id.'/cover')->assertOk()
            ->assertHeader('Content-Type', $mime)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertSame(file_get_contents($file->getPathname()), $response->streamedContent());
        $admin = User::create(['email' => 'cover-admin@example.test', 'status' => 'approved', 'admin' => true]);
        $this->actingAs($admin)->get('/admin/groups/'.$group->id.'/cover')->assertOk();
        $this->get('/groups/'.$group->id.'/cover')->assertForbidden();
        $other = User::create(['email' => 'other-cover@example.test', 'status' => 'approved']);
        $this->actingAs($other)->get('/groups/'.$group->id.'/cover')->assertNotFound();
        $this->get('/admin/groups/'.$group->id.'/cover')->assertForbidden();
        Storage::disk('local')->delete($group->cover_path);
        $this->actingAs($this->owner)->get('/groups/'.$group->id.'/cover')->assertNotFound();
        $this->actingAs($admin)->get('/admin/groups/'.$group->id.'/cover')->assertNotFound();
    }

    public function test_invalid_content_and_oversized_images_are_rejected_without_storage(): void
    {
        $files = [UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('test.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')),
            UploadedFile::fake()->createWithContent('test.pdf', '%PDF-1.4 fake'),
            UploadedFile::fake()->createWithContent('spoof.png', 'not an image'),
            UploadedFile::fake()->create('spoof.jpg', 1, 'image/jpeg'),
            Fixture::cover()->size(5121)];
        foreach ($files as $file) {
            try {
                app(GroupWorkflow::class)->save($this->group, $this->owner, ['cover' => $file]);
                $this->fail('Invalid cover accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('cover', $exception->errors());
            }
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertNull($this->group->fresh()->cover_path);
    }

    public function test_replacement_preserves_old_file_during_transaction_then_removes_it(): void
    {
        $workflow = app(GroupWorkflow::class);
        $first = $workflow->save($this->group, $this->owner, ['cover' => Fixture::cover()]);
        $oldPath = $first->cover_path;
        $observed = false;
        Group::updating(function (Group $group) use ($oldPath, &$observed): void {
            if ($group->isDirty('cover_path')) {
                Storage::disk('local')->assertExists($oldPath);
                Storage::disk('local')->assertExists($group->cover_path);
                $observed = true;
            }
        });
        try {
            $updated = $workflow->save($first, $this->owner, ['cover' => Fixture::cover('jpg')]);
        } finally {
            Group::flushEventListeners();
            Group::clearBootedModels();
        }
        $this->assertTrue($observed);
        $this->assertNotSame($oldPath, $updated->cover_path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($updated->cover_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_original_filename_is_sanitized_and_never_used_as_path(): void
    {
        $original = Fixture::cover();
        $file = new UploadedFile($original->getPathname(), "..\\folder\\bad\nname.png", 'image/png', null, true);
        $saved = app(GroupWorkflow::class)->save($this->group, $this->owner, ['cover' => $file]);
        $this->assertSame('badname.png', $saved->cover_original_name);
        $this->assertStringNotContainsString('badname', $saved->cover_path);
    }

    public function test_storage_and_cleanup_failures_are_explicit(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        $disk->shouldReceive('exists')->once()->andReturn(false);
        try {
            app(GroupCovers::class)->persist(Fixture::cover(), fn () => throw new \RuntimeException('Must not write'));
            $this->fail('Storage error must propagate');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cover', $exception->errors());
        }
        $disk->shouldReceive('putFileAs')->once()->andReturn('synthetic');
        $disk->shouldReceive('exists')->once()->with('group-covers/old.png')->andReturn(true);
        $disk->shouldReceive('delete')->once()->with('group-covers/old.png')->andReturn(false);
        $this->expectException(ValidationException::class);
        app(GroupCovers::class)->persist(Fixture::cover(), fn () => [$this->group, 'group-covers/old.png']);
    }
}
