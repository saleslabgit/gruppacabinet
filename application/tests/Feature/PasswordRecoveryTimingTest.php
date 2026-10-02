<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PasswordSetupService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PasswordRecoveryTimingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private array $hashes = [];

    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        $this->seed(DatabaseSeeder::class);
        $this->user = User::where('email', 'psychologist@gruppa.test')->sole();
        Mail::fake();
        Log::listen(function (MessageLogged $event): void {
            $this->logs[] = [$event->level, $event->message, $event->context];
        });
    }

    private function observeHasher(string $driver = 'bcrypt'): Hasher
    {
        config(['hashing.driver' => $driver, 'hashing.bcrypt.rounds' => 6,
            'hashing.argon.memory' => 1024, 'hashing.argon.time' => 2, 'hashing.argon.threads' => 1]);
        app('hash')->forgetDrivers();
        $hasher = app('hash')->driver();
        $spy = \Mockery::mock(app('hash'));
        $spy->shouldReceive('make')->andReturnUsing(function ($value, array $options = []) use ($hasher): string {
            $hash = $hasher->make($value, $options);
            $this->hashes[] = ['value' => $value, 'options' => $options, 'hash' => $hash];

            return $hash;
        });
        Hash::swap($spy);
        $this->assertSame(app('hash'), app(PasswordSetupService::class)->broker()->getRepository()->getHasher());

        return $hasher;
    }

    private function assertPrivateValuesNotLogged(string $email): void
    {
        $logged = serialize($this->logs);
        $this->assertStringNotContainsString($email, $logged);
        foreach ($this->hashes as $entry) {
            $this->assertStringNotContainsString($entry['value'], $logged);
            $this->assertStringNotContainsString($entry['hash'], $logged);
        }
    }

    public static function accountAndHasher(): array
    {
        $cases = [];
        foreach (['bcrypt', 'argon2id'] as $driver) {
            foreach (['eligible', 'no-password', 'unknown', 'pending', 'rejected', 'disabled', 'deleted', 'admin'] as $state) {
                $cases[$driver.'-'.$state] = [$driver, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('accountAndHasher')]
    public function test_valid_request_performs_one_configured_framework_hash(string $driver, string $state): void
    {
        if ($state === 'deleted') {
            $this->user->delete();
        } elseif (! in_array($state, ['eligible', 'unknown'], true)) {
            $this->user->update(match ($state) {
                'no-password' => ['password' => null],
                'pending', 'rejected' => ['status' => $state],
                default => [$state => true],
            });
        }
        $email = $state === 'unknown' ? 'unknown@example.test' : $this->user->email;
        $hasher = $this->observeHasher($driver);
        $this->post('/password/forgot', ['email' => $email])->assertOk()->assertViewHas('variant', 'success');
        $this->assertCount(1, $this->hashes);
        $entry = $this->hashes[0];
        $this->assertSame(64, strlen($entry['value']));
        $this->assertSame([], $entry['options']); // No cheaper per-call overrides.
        $this->assertTrue($hasher->check($entry['value'], $entry['hash']));
        $info = $hasher->info($entry['hash']);
        $this->assertSame($driver, $info['algoName']);
        $this->assertSame($driver === 'bcrypt' ? ['cost' => 6] : ['memory_cost' => 1024, 'time_cost' => 2, 'threads' => 1], $info['options']);
        $eligible = in_array($state, ['eligible', 'no-password'], true);
        $this->assertDatabaseCount('password_reset_tokens', $eligible ? 1 : 0);
        $this->assertDatabaseCount('jobs', $eligible ? 1 : 0);
        if ($eligible) {
            $this->assertSame($entry['hash'], DB::table('password_reset_tokens')->value('token'));
            $this->assertTrue(app(PasswordSetupService::class)->valid($email, $entry['value']));
        }
        Mail::assertNothingSent();
        $this->assertPrivateValuesNotLogged($email);
    }

    public static function hashDrivers(): array
    {
        return [['bcrypt'], ['argon2id']];
    }

    #[DataProvider('hashDrivers')]
    public function test_locked_recheck_after_account_is_disabled_still_hashes_once(string $driver): void
    {
        $setup = app(PasswordSetupService::class);
        $hasher = $this->observeHasher($driver);
        $success = $this->post('/password/forgot', ['email' => 'unknown@example.test'])->assertOk();
        $this->hashes = [];
        $this->partialMock(PasswordSetupService::class)->shouldReceive('invite')->once()
            ->with($this->user->id)->andReturnUsing(function (int $userId) use ($setup): bool {
                // Called only after the controller has resolved an eligible account.
                $this->assertTrue(PasswordSetupService::eligible($this->user->fresh()));
                User::whereKey($userId)->update(['disabled' => true]);
                // Run the real service, including its locked reload, rather than mock its result.
                $issued = $setup->invite($userId);
                $this->assertFalse($issued);
                $this->assertCount(0, $this->hashes);

                return $issued;
            });

        $response = $this->post('/password/forgot', ['email' => $this->user->email])
            ->assertOk()->assertViewHas('variant', 'success');
        $this->assertTrue($this->user->fresh()->disabled);
        $this->assertCount(1, $this->hashes);
        $entry = $this->hashes[0];
        $this->assertSame(64, strlen($entry['value']));
        $this->assertSame([], $entry['options']);
        $this->assertTrue($hasher->check($entry['value'], $entry['hash']));
        $this->assertSame($driver, $hasher->info($entry['hash'])['algoName']);
        $this->assertSame($driver === 'bcrypt' ? ['cost' => 6] : ['memory_cost' => 1024, 'time_cost' => 2, 'threads' => 1], $hasher->info($entry['hash'])['options']);
        $this->assertSame($success->getContent(), $response->getContent());
        foreach (['Cache-Control', 'Referrer-Policy', 'Location', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
            $this->assertSame($success->headers->get($header), $response->headers->get($header));
        }
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertStringNotContainsString($entry['value'], serialize(session()->all()));
        $this->assertStringNotContainsString($entry['hash'], serialize(session()->all()));
        Mail::assertNothingSent();
        $this->assertPrivateValuesNotLogged($this->user->email);
    }

    public function test_malformed_and_throttled_requests_do_not_hash(): void
    {
        $this->observeHasher();
        $this->post('/password/forgot', ['email' => 'malformed'])->assertStatus(422);
        $this->assertCount(0, $this->hashes);
        $this->post('/password/forgot', ['email' => 'unknown@example.test'])->assertOk();
        $this->post('/password/forgot', ['email' => ' UNKNOWN@example.test '])->assertStatus(429);
        $this->assertCount(1, $this->hashes);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertPrivateValuesNotLogged('unknown@example.test');
    }

    public function test_dummy_values_are_fresh_and_discarded(): void
    {
        $this->observeHasher();
        $this->post('/password/forgot', ['email' => 'unknown@example.test'])->assertOk();
        $this->travel(61)->seconds();
        $this->post('/password/forgot', ['email' => 'unknown@example.test'])->assertOk();
        $this->assertCount(2, $this->hashes);
        $this->assertNotSame($this->hashes[0]['value'], $this->hashes[1]['value']);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('jobs', 0);
        foreach ($this->hashes as $entry) {
            $this->assertStringNotContainsString($entry['value'], serialize(session()->all()));
            $this->assertStringNotContainsString($entry['hash'], serialize(session()->all()));
        }
        $this->assertPrivateValuesNotLogged('unknown@example.test');
    }

    public function test_queue_failure_still_hashes_once_and_rolls_back_without_sensitive_diagnostics(): void
    {
        $setup = app(PasswordSetupService::class);
        $previousToken = $setup->broker()->createToken($this->user);
        $previousHash = DB::table('password_reset_tokens')->value('token');
        $this->observeHasher();
        $success = $this->post('/password/forgot', ['email' => 'unknown@example.test'])->assertOk();
        $this->hashes = [];
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function () use ($previousToken): void {
            throw new RuntimeException($this->user->email.' '.$previousToken.' '.serialize($this->hashes));
        });
        $failure = $this->post('/password/forgot', ['email' => $this->user->email])->assertOk();
        $this->assertCount(1, $this->hashes);
        $this->assertSame($success->getContent(), $failure->getContent());
        foreach (['Cache-Control', 'Referrer-Policy', 'Location', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
            $this->assertSame($success->headers->get($header), $failure->headers->get($header));
        }
        $this->assertSame($previousHash, DB::table('password_reset_tokens')->value('token'));
        $this->assertTrue($setup->valid($this->user->email, $previousToken));
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame([['error', 'Password recovery request could not be queued.', ['exception_type' => RuntimeException::class]]], $this->logs);
        $this->assertPrivateValuesNotLogged($this->user->email);
        $this->assertStringNotContainsString($previousToken, serialize($this->logs));
        $this->assertStringNotContainsString($previousHash, serialize($this->logs));
    }
}
