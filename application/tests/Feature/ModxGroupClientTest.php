<?php

namespace Tests\Feature;

use App\Exceptions\ModxGroupSyncException;
use App\Services\Modx\GroupClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModxGroupClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.modx.base_url' => 'https://modx.example.test/api/v1/', 'services.modx.token' => 'synthetic-token',
            'services.modx.connect_timeout' => 5, 'services.modx.sync_timeout' => 60]);
    }

    public function test_raw_body_headers_timeout_redirect_policy_and_boolean_response(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame(60, $options['timeout']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('{"resource":{"pagetitle":"Synthetic"}}', $request->body());
            $this->assertSame(['caller-key'], $request->header('Idempotency-Key'));
            $this->assertSame(['Bearer synthetic-token'], $request->header('Authorization'));
            $this->assertSame(['application/json'], $request->header('Accept'));
            $this->assertSame(['application/json'], $request->header('Content-Type'));
            $this->assertSame('https://modx.example.test/api/v1/cabinet/resources/sync', $request->url());
            $this->assertSame('POST', $request->method());

            return Http::response(['data' => ['resource_id' => 7, 'created' => '1', 'updated' => '0', 'cover_path' => null, 'cover_cleanup_warning' => true]]);
        });
        $this->assertSame(['resource_id' => 7, 'created' => true, 'updated' => false, 'cover_path' => null, 'cover_cleanup_warning' => true],
            app(GroupClient::class)->sync('{"resource":{"pagetitle":"Synthetic"}}', 'caller-key'));
    }

    public static function failures(): array
    {
        return [
            [401, [], 'authorization', false], [403, [], 'authorization', false], [404, [], 'resource_not_found', false],
            [422, [], 'remote_validation', false], [429, [], 'rate_limited', true], [500, [], 'remote_unavailable', true],
            [503, [], 'remote_unavailable', true], [302, [], 'redirect', false],
            [409, ['error' => ['message' => 'An idempotent request is already in progress']], 'in_progress', true],
            [409, ['error' => ['message' => 'Idempotency-Key was reused with a different request body']], 'idempotency_conflict', false],
            [409, [], 'remote_validation', false], [200, 'not JSON', 'invalid_response', false],
            [200, [], 'invalid_response', false],
            [200, ['data' => ['resource_id' => '12', 'created' => true, 'updated' => false]], 'invalid_response', false],
            [200, ['data' => ['resource_id' => 0, 'created' => true, 'updated' => false]], 'invalid_response', false],
            [200, ['data' => ['resource_id' => 12, 'created' => true, 'updated' => true]], 'invalid_response', false],
            [200, ['data' => ['resource_id' => 12, 'created' => false, 'updated' => false]], 'invalid_response', false],
            [200, ['data' => ['resource_id' => 12, 'created' => null, 'updated' => true]], 'invalid_response', false],
            [200, ['data' => ['resource_id' => 12, 'created' => 'yes', 'updated' => false]], 'invalid_response', false],
            [200, ['data' => ['resource_id' => 12, 'created' => true, 'updated' => false, 'cover_path' => []]], 'invalid_response', false],
        ];
    }

    #[DataProvider('failures')]
    public function test_errors_are_classified_and_sanitized(int $status, array|string $body, string $code, bool $retryable): void
    {
        Http::fake(['*' => Http::response($body, $status, ['Location' => 'https://other.example.test'])]);
        try {
            app(GroupClient::class)->sync('{"private":"HTML base64 leader private-path"}', 'safe-key');
            $this->fail('Expected failure');
        } catch (ModxGroupSyncException $e) {
            $this->assertSame($code, $e->safeCode);
            $this->assertSame($retryable, $e->retryable);
            $this->assertNull($e->getPrevious());
            $this->assertSame('MODX group synchronization: '.$code, $e->getMessage());
            foreach (['synthetic-token', 'HTML base64 leader private-path', 'other.example.test'] as $private) {
                $this->assertStringNotContainsString($private, (string) $e);
            }
        }
        Http::assertSentCount(1);
    }

    public function test_connection_exception_has_no_original_exception_chain(): void
    {
        Http::fake(fn () => throw new ConnectionException('synthetic-token HTML base64 leader private-path'));
        try {
            app(GroupClient::class)->sync('{}', 'safe-key');
            $this->fail('Expected failure');
        } catch (ModxGroupSyncException $e) {
            $this->assertSame('connection', $e->safeCode);
            $this->assertTrue($e->retryable);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('synthetic-token', (string) $e);
        }
    }

    public static function invalidConfiguration(): array
    {
        return [['base_url', 'http://modx.example.test'], ['base_url', 'https://user:pass@modx.example.test'],
            ['base_url', 'https://modx.example.test?secret=x'], ['token', ''], ['token', "x\r\ny"],
            ['sync_timeout', 61], ['sync_timeout', 0], ['connect_timeout', 61]];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_configuration_sends_nothing(string $key, mixed $value): void
    {
        config(['services.modx.'.$key => $value]);
        Http::fake();
        try {
            app(GroupClient::class)->sync('{}', 'safe-key');
            $this->fail('Expected configuration failure');
        } catch (ModxGroupSyncException $e) {
            $this->assertSame('configuration', $e->safeCode);
        }
        Http::assertNothingSent();
    }
}
