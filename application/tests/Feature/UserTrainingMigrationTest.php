<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserDocument;
use App\Models\UserTraining;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserTrainingMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_additive_backfill_and_rollback_preserve_users_documents_and_private_files(): void
    {
        Storage::fake('local');
        $migration = require database_path('migrations/2026_09_24_000001_create_user_trainings.php');
        $migration->down();
        $legacy = User::create(['email' => 'legacy@example.test', 'modality_program' => 'Legacy program',
            'training_center' => 'Legacy center', 'graduation_year' => 2020, 'training_hours' => 120]);
        $without = User::create(['email' => 'without@example.test']);
        $zero = User::create(['email' => 'zero@example.test', 'training_hours' => 0]);
        $deleted = User::create(['email' => 'deleted@example.test', 'modality_program' => 'Deleted training']);
        $deleted->delete();
        $usersBefore = DB::table('gp_users')->orderBy('id')->get()->toJson();
        foreach ([[$legacy, 'certificate'], [$legacy, 'certificate'], [$legacy, 'diploma'], [$without, 'certificate']] as $index => [$user, $type]) {
            $document = $user->documents()->create(['type' => $type, 'path' => 'synthetic/'.$index.'.pdf',
                'original_name' => 'Synthetic.pdf', 'mime_type' => 'application/pdf', 'size' => 4]);
            Storage::disk('local')->put($document->path, 'test');
        }
        $documentsBefore = DB::table('gp_user_documents')->orderBy('id')->get()->toJson();
        $migration->up();
        $this->assertSame($usersBefore, DB::table('gp_users')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('gp_user_trainings', 3);
        $training = $legacy->trainings()->sole();
        $this->assertSame(0, $training->position);
        $this->assertSame('Legacy program', $training->modality_program);
        $this->assertSame('Legacy center', $training->training_center);
        $this->assertSame(2020, $training->graduation_year);
        $this->assertSame(120, $training->training_hours);
        $this->assertTrue($training->user->is($legacy));
        $this->assertCount(2, $training->documents);
        $this->assertSame(0, $zero->trainings()->sole()->training_hours);
        $this->assertSame('Deleted training', $deleted->trainings()->sole()->modality_program);
        $this->assertNull($without->documents()->sole()->user_training_id);
        $this->assertNull($legacy->documents()->where('type', 'diploma')->sole()->user_training_id);
        foreach ($legacy->documents()->where('type', 'certificate')->get() as $certificate) {
            $this->assertSame($training->id, $certificate->user_training_id);
        }
        $training->delete();
        foreach (UserDocument::all() as $document) {
            $this->assertNull($document->user_training_id);
            $this->assertSame('test', Storage::disk('local')->get($document->path));
        }
        $migration->down();
        $this->assertFalse(Schema::hasTable('gp_user_trainings'));
        $this->assertFalse(Schema::hasColumn('gp_user_documents', 'user_training_id'));
        $this->assertSame($usersBefore, DB::table('gp_users')->orderBy('id')->get()->toJson());
        $this->assertSame($documentsBefore, DB::table('gp_user_documents')->orderBy('id')->get()->toJson());
        $this->assertCount(4, Storage::disk('local')->allFiles());
        $migration->up();
        $this->assertDatabaseCount('gp_user_trainings', 3);
    }

    public function test_database_enforces_unique_user_position(): void
    {
        $user = User::create(['email' => 'unique@example.test']);
        $user->trainings()->create(['position' => 0, 'training_hours' => 0]);
        $this->expectException(QueryException::class);
        $user->trainings()->create(['position' => 0, 'modality_program' => 'Duplicate']);
    }

    public function test_current_training_model_rejects_empty_rows(): void
    {
        $user = User::create(['email' => 'empty@example.test']);
        $this->expectException(\InvalidArgumentException::class);
        UserTraining::create(['user_id' => $user->id, 'position' => 0, 'modality_program' => '  ']);
    }
}
