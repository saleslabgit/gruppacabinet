<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\IntegrationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class IntegrationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function parallel(string $endpoint, array $payload, bool $sameId): array
    {
        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/integration-worker.php')], base_path());
            $process->setTimeout(90);
            $process->setInput(json_encode(['endpoint' => $endpoint, 'payload' => $payload, 'request_id' => $sameId ? 'concurrent' : 'concurrent-'.$i]));
            $process->start();
            $processes[] = $process;
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertContains($result['status'], [200, 201], $process->getOutput());
            $results[] = $result;
        }

        return $results;
    }

    public function test_concurrent_application_duplicates_create_exactly_one_row(): void
    {
        $owner = User::create(['email' => 'concurrency-owner@example.test', 'status' => 'approved']);
        $group = Group::create(['owner_id' => $owner->id, 'status' => 'active']);
        $results = $this->parallel('group-applications', ['group_uuid' => $group->public_uuid, 'last_name' => 'Synthetic', 'first_name' => 'Concurrent', 'phone' => '+12025550100'], true);
        foreach ($results as $result) {
            $this->assertSame($results[0], $result);
        }
        $this->assertDatabaseCount('gp_group_applications', 1);
        $this->assertDatabaseCount('gp_integration_requests', 1);
    }

    public function test_concurrent_psychologist_duplicates_and_distinct_ids_same_email(): void
    {
        $payload = ['email' => 'concurrent@example.test', 'personal_data_consent_at' => '2026-09-21T12:00:00Z', 'personal_data_consent_version' => 'synthetic-v1', 'trainings' => [['modality_program' => 'Concurrent A'], ['training_hours' => 10]]];
        $results = $this->parallel('psychologists', $payload, true);
        foreach ($results as $result) {
            $this->assertSame($results[0], $result);
        }
        $this->assertDatabaseCount('gp_users', 1);
        $this->assertDatabaseCount('gp_user_trainings', 2);
        $this->assertDatabaseCount('gp_integration_requests', 1);
        $payload['email'] = 'different-ids@example.test';
        $results = $this->parallel('psychologists', $payload, false);
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result['status'] === 201)));
        $this->assertDatabaseCount('gp_users', 2);
        $this->assertDatabaseCount('gp_user_trainings', 4);
        $this->assertDatabaseCount('gp_integration_requests', 5);
    }

    public function test_concurrent_reuse_of_deleted_email_has_one_new_active_user(): void
    {
        foreach ([true, false] as $sameId) {
            $email = $sameId ? 'same@example.test' : 'distinct@example.test';
            $old = User::create(['email' => $email, 'status' => 'approved']);
            $old->trainings()->create(['position' => 0, 'training_hours' => 10]);
            $old->delete();
            $before = $old->fresh()->getAttributes();
            // Reuse worker IDs only after the previous case's assertions.
            IntegrationRequest::query()->delete();
            $results = $this->parallel('psychologists', ['email' => $email, 'personal_data_consent_at' => '2026-09-21T12:00:00Z', 'personal_data_consent_version' => 'synthetic', 'trainings' => [['training_hours' => 20]]], $sameId);
            $new = User::where('email', $email)->sole();
            $this->assertNotSame($old->id, $new->id);
            $this->assertSame($sameId ? 4 : 1, count(array_filter($results, fn ($result) => $result['status'] === 201)));
            $this->assertSame($before, $old->fresh()->getAttributes());
            $this->assertSame(10, $old->trainings()->sole()->training_hours);
            $this->assertSame(20, $new->trainings()->sole()->training_hours);
        }
    }
}
