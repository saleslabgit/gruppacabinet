<?php

namespace App\Support;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DeploymentPreflight
{
    /** @return array<int, array{check: string, ok: bool, detail: string}> */
    public function checks(): array
    {
        $checks = [];
        $add = function (string $name, bool $ok, string $detail = '') use (&$checks): void {
            $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
        };
        $deployment = app()->environment(['staging', 'production']);
        // The committed project PHP constraint is ^8.2.
        $add('PHP (^8.2)', $this->supportedPhp(PHP_VERSION), PHP_VERSION);
        $requirements = json_decode(file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR)['require'];
        $lockfile = json_decode(file_get_contents(base_path('composer.lock')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($lockfile['packages'] as $package) {
            $requirements += $package['require'] ?? [];
        }
        foreach (array_keys($requirements) as $requirement) {
            if (str_starts_with($requirement, 'ext-')) {
                $extension = substr($requirement, 4);
                $add('Extension '.$extension, extension_loaded($extension));
            }
        }
        // queue:work timeouts require pcntl, independently of Composer web requirements.
        $add('CLI worker timeout (pcntl)', extension_loaded('pcntl'));
        $add('Writable storage', is_writable(storage_path()) && is_writable(storage_path('framework')) && is_writable(storage_path('logs')));
        $add('Writable bootstrap/cache', is_writable(base_path('bootstrap/cache')));
        $add('APP_KEY presence', $this->configured('app.key'), $this->presence('app.key'));
        $add('Database sessions', ! $deployment || config('session.driver') === 'database');
        $add('Database queue', config('queue.default') === 'database');
        $add('Shared database cache', ! $deployment || config('cache.default') === 'database');
        $url = parse_url((string) config('app.url'));
        $add('HTTPS /cabinet APP_URL', ! $deployment || (is_array($url) && ($url['scheme'] ?? '') === 'https'
            && ! empty($url['host']) && rtrim($url['path'] ?? '', '/') === '/cabinet'
            && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment'])));
        $add('APP_DEBUG disabled', ! $deployment || config('app.debug') === false);
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        $add('SMTP delivery configured', ! $deployment || ($transport === 'smtp'
            && $this->configured('mail.mailers.'.config('mail.default').'.host') && $this->configured('mail.from.address')));
        $add('Integration secret', ! $deployment || $this->configured('integration.secret'), $this->presence('integration.secret'));
        $environment = config('webpay.environment');
        $add('WEBPAY environment', in_array($environment, ['sandbox', 'production'], true),
            in_array($environment, ['sandbox', 'production'], true) ? $environment : 'invalid');
        foreach (['store_id', 'secret_key', 'api_username', 'api_password'] as $field) {
            $add('WEBPAY '.$field, ! $deployment || $this->configured('webpay.'.$field), $this->presence('webpay.'.$field));
        }
        try {
            $db = DB::connection();
            $driver = $db->getDriverName();
            $add('Database driver', $driver === 'mysql', in_array($driver, ['mysql', 'sqlite', 'pgsql', 'sqlsrv'], true) ? $driver : 'unsupported');
            if ($driver === 'mysql') {
                $identity = $db->selectOne('SELECT VERSION() AS version, @@version_comment AS vendor');
                $version = preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+/', $identity->version, $matches) ? $matches[0] : 'unknown';
                $maria = stripos($identity->version.' '.$identity->vendor, 'mariadb') !== false;
                $mysql = ! $maria && stripos($identity->vendor, 'mysql') !== false;
                $add('Database server', $mysql && version_compare($version, '8.0.0', '>='),
                    ($maria ? 'MariaDB (not the verified MySQL contract)' : ($mysql ? 'MySQL' : 'Unverified MySQL-compatible vendor')).' '.$version);
            }
            $tables = [
                'Sessions' => [config('session.table'), config('session.connection')],
                'Queue jobs' => [config('queue.connections.database.table'), config('queue.connections.database.connection')],
                'Queue batches' => [config('queue.batching.table'), config('queue.batching.database')],
                'Failed jobs' => [config('queue.failed.table'), config('queue.failed.database')],
                'Password tokens' => [config('auth.passwords.users.table'), null],
                'Users' => ['gp_users', null],
                'Groups' => ['gp_groups', null],
                'Applications' => ['gp_group_applications', null],
                'Payments' => ['gp_payments', null],
                'Payment notifications' => ['gp_payment_notifications', null],
                'Integration requests' => ['gp_integration_requests', null],
                'Settings' => ['gp_settings', null],
            ];
            foreach ($tables as $label => [$table, $connection]) {
                // Labels are fixed; never echo arbitrary configuration/connection values.
                $add($label.' table', is_string($table) && DB::connection($connection)->getSchemaBuilder()->hasTable($table));
            }
        } catch (Throwable) {
            $add('Database connection/schema', false, 'unavailable; check private server configuration');
        }
        try {
            /** @var Repository $store */
            $store = Cache::store('database');
            /** @var DatabaseStore $lockStore */
            $lockStore = $store->getStore();
            $key = 'deployment-preflight:'.Str::uuid();
            $lock = $lockStore->lock($key, 10);
            $acquired = false;
            try {
                // Only a disposable technical cache entry and lock are written, never business data.
                $store->put($key, 'probe', 10);
                $add('Database cache read/write', $store->get($key) === 'probe');
                $acquired = $lock->get();
                $second = $lockStore->lock($key, 10);
                $secondAcquired = $second->get();
                if ($secondAcquired) {
                    $second->release();
                }
                $add('Database cache lock exclusion', $acquired && ! $secondAcquired);
            } finally {
                if ($acquired) {
                    $lock->release();
                }
                $store->forget($key);
            }
        } catch (Throwable) {
            $add('Database cache/locks', false, 'unavailable; verify migration and shared connection');
        }

        return $checks;
    }

    private function supportedPhp(string $version): bool
    {
        return version_compare($version, '8.2.0', '>=') && version_compare($version, '9.0.0', '<');
    }

    private function configured(string $key): bool
    {
        return is_string(config($key)) && trim(config($key)) !== '';
    }

    private function presence(string $key): string
    {
        return $this->configured($key) ? 'configured' : 'missing';
    }
}
