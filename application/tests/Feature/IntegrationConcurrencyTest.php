<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class IntegrationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private function parallel(string $endpoint, array $payload, bool $sameId): array
    {
        $secret = bin2hex(random_bytes(32));
        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/integration-worker.php')], base_path(), ['TEST_INTEGRATION_SECRET' => $secret]);
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
        $payload = ['questionnaire' => ['email' => 'concurrent@example.test', 'personal_data_consent_at' => '2026-09-21T12:00:00Z', 'personal_data_consent_version' => 'synthetic-v1'], 'documents' => []];
        $results = $this->parallel('psychologists', $payload, true);
        foreach ($results as $result) {
            $this->assertSame($results[0], $result);
        }
        $this->assertDatabaseCount('gp_users', 1);
        $this->assertDatabaseCount('gp_integration_requests', 1);
        $payload['questionnaire']['email'] = 'different-ids@example.test';
        $results = $this->parallel('psychologists', $payload, false);
        $this->assertSame(1, count(array_filter($results, fn ($result) => $result['status'] === 201)));
        $this->assertDatabaseCount('gp_users', 2);
        $this->assertDatabaseCount('gp_integration_requests', 5);
    }
}
