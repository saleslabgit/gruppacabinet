<?php

namespace App\Services\Modx;

use App\Exceptions\ModxGroupSyncException;
use Illuminate\Support\Facades\Http;
use Throwable;

class GroupClient
{
    /** @return array{resource_id: int, created: bool, updated: bool, cover_path: ?string, cover_cleanup_warning: bool} */
    public function sync(#[\SensitiveParameter] string $body, string $key): array
    {
        $data = $this->request($body, $key, 'sync');
        foreach (['created', 'updated'] as $flag) {
            if (! array_key_exists($flag, $data) || ! in_array($data[$flag], [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                throw new ModxGroupSyncException('invalid_response');
            }
            $data[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
        }
        $cover = $data['cover_path'] ?? null;
        $warning = $data['cover_cleanup_warning'] ?? false;
        if ($data['created'] === $data['updated'] || ($cover !== null && (! is_string($cover) || strlen($cover) > 255))
            || ! is_bool($warning)) {
            throw new ModxGroupSyncException('invalid_response');
        }

        return ['resource_id' => $data['resource_id'], 'created' => $data['created'], 'updated' => $data['updated'],
            'cover_path' => $cover, 'cover_cleanup_warning' => $warning];
    }

    /** @return array{resource_id: int, published: bool, changed: bool} */
    public function publication(#[\SensitiveParameter] string $body, string $key, int $resourceId, bool $published): array
    {
        $data = $this->request($body, $key, 'publication');
        foreach (['published', 'changed'] as $flag) {
            if (! array_key_exists($flag, $data) || ! in_array($data[$flag], [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
                throw new ModxGroupSyncException('invalid_response');
            }
            $data[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
        }
        if ($data['resource_id'] !== $resourceId) {
            throw new ModxGroupSyncException('resource_id_conflict');
        }
        if ($data['published'] !== $published) {
            throw new ModxGroupSyncException('invalid_response');
        }

        return ['resource_id' => $resourceId, 'published' => $published, 'changed' => $data['changed']];
    }

    private function request(#[\SensitiveParameter] string $body, string $key, string $endpoint): array
    {
        $base = config('services.modx.base_url');
        $token = config('services.modx.token');
        $connect = filter_var(config('services.modx.connect_timeout'), FILTER_VALIDATE_INT);
        $timeout = filter_var(config('services.modx.sync_timeout'), FILTER_VALIDATE_INT);
        if (! is_string($base) || ! filter_var($base, FILTER_VALIDATE_URL)
            || parse_url($base, PHP_URL_SCHEME) !== 'https'
            || parse_url($base, PHP_URL_USER) !== null || parse_url($base, PHP_URL_PASS) !== null
            || parse_url($base, PHP_URL_QUERY) !== null || parse_url($base, PHP_URL_FRAGMENT) !== null
            || ! is_string($token) || trim($token) === '' || preg_match('/[\r\n]/', $token)
            || $connect === false || $timeout === false || $connect < 1 || $timeout < $connect || $timeout > 60) {
            throw new ModxGroupSyncException('configuration');
        }
        try {
            $response = Http::acceptJson()->withToken($token)->withoutRedirecting()
                ->connectTimeout($connect)->timeout($timeout)->withHeaders(['Idempotency-Key' => $key])
                ->withBody($body, 'application/json')->post(rtrim($base, '/').'/cabinet/resources/'.$endpoint);
        } catch (Throwable) {
            throw new ModxGroupSyncException('connection', true);
        }
        $status = $response->status();
        if (! $response->successful()) {
            if ($status === 409) {
                $message = strtolower($response->body());
                if (str_contains($message, 'idempotent request is already in progress')) {
                    throw new ModxGroupSyncException('in_progress', true);
                }
                if (str_contains($message, 'idempotency-key was reused with a different request body')) {
                    throw new ModxGroupSyncException('idempotency_conflict');
                }
            }
            throw new ModxGroupSyncException(match (true) {
                $status === 429 => 'rate_limited', $status >= 500 => 'remote_unavailable',
                in_array($status, [401, 403], true) => 'authorization', $status === 404 => 'resource_not_found',
                $status >= 300 && $status < 400 => 'redirect', default => 'remote_validation',
            }, $status === 429 || $status >= 500);
        }
        $data = $response->json('data');
        if (! is_array($data) || ! is_int($data['resource_id'] ?? null) || $data['resource_id'] < 1) {
            throw new ModxGroupSyncException('invalid_response');
        }

        return $data;
    }
}
