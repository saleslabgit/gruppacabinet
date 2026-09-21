<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingService
{
    public const PLACEMENT_PRICE_MINOR_UNITS = 'placement_price_minor_units';

    public const EXTENSION_PRICE_MINOR_UNITS = 'extension_price_minor_units';

    public const PLACEMENT_DURATION_DAYS = 'placement_duration_days';

    public const EXPIRY_WARNING_DAYS = 'expiry_warning_days';

    public const EXPIRED_EXTENSION_WINDOW_DAYS = 'expired_extension_window_days';

    public const PARTICIPANT_APPLICATION_RETENTION_MONTHS = 'participant_application_retention_months';

    public const PASSWORD_SETUP_LINK_TTL_HOURS = 'password_setup_link_ttl_hours';

    public const PRICE_KEYS = [self::PLACEMENT_PRICE_MINOR_UNITS, self::EXTENSION_PRICE_MINOR_UNITS];

    public const INTEGER_KEYS = [self::PLACEMENT_DURATION_DAYS, self::EXPIRY_WARNING_DAYS, self::EXPIRED_EXTENSION_WINDOW_DAYS, self::PARTICIPANT_APPLICATION_RETENTION_MONTHS, self::PASSWORD_SETUP_LINK_TTL_HOURS];

    // MySQL TIMESTAMP ends at 2038-01-19 03:14:07 UTC.
    public static function maximumPlacementDays(): int
    {
        return max(0, intdiv(2147483647 - now()->timestamp, 86400));
    }

    /** @return array<string, int|null> */
    public function values(): array
    {
        return [
            self::PLACEMENT_PRICE_MINOR_UNITS => $this->placementPriceMinorUnits(),
            self::EXTENSION_PRICE_MINOR_UNITS => $this->extensionPriceMinorUnits(),
            self::PLACEMENT_DURATION_DAYS => $this->placementDurationDays(),
            self::EXPIRY_WARNING_DAYS => $this->expiryWarningDays(),
            self::EXPIRED_EXTENSION_WINDOW_DAYS => $this->expiredExtensionWindowDays(),
            self::PARTICIPANT_APPLICATION_RETENTION_MONTHS => $this->participantApplicationRetentionMonths(),
            self::PASSWORD_SETUP_LINK_TTL_HOURS => $this->passwordSetupLinkTtlHours(),
        ];
    }

    /** @param array<string, int|null> $values */
    public function update(array $values, User $actor): void
    {
        $keys = array_merge(self::PRICE_KEYS, self::INTEGER_KEYS);
        if (array_diff(array_keys($values), $keys) !== [] || array_diff($keys, array_keys($values)) !== []) {
            throw new DomainException('Exactly the seven known settings are required.');
        }
        foreach ($values as $key => $value) {
            $price = in_array($key, self::PRICE_KEYS, true);
            if (($price && $value === null) || (is_int($value) && $value >= ($price ? 0 : 1))) {
                continue;
            }
            throw new DomainException("Invalid typed value for [{$key}].");
        }
        if ($values[self::EXPIRY_WARNING_DAYS] >= $values[self::PLACEMENT_DURATION_DAYS]
            || $values[self::PLACEMENT_DURATION_DAYS] > self::maximumPlacementDays()) {
            throw new DomainException('Invalid placement/warning duration.');
        }
        DB::transaction(function () use ($values, $actor, $keys): void {
            $rows = Setting::query()->whereIn('key', $keys)->orderBy('id')->lockForUpdate()->get()->keyBy('key');
            if ($rows->count() !== count($keys)) {
                throw new DomainException('Required settings are missing.');
            }
            foreach ($values as $key => $value) {
                $row = $rows[$key];
                if ($row->type !== 'integer' || ($row->value !== null && filter_var($row->value, FILTER_VALIDATE_INT) === false)) {
                    throw new DomainException("Invalid stored setting [{$key}].");
                }
                $old = $row->value === null ? null : (int) $row->value;
                if ($old === $value) {
                    continue;
                }
                $row->update(['value' => $value === null ? null : (string) $value, 'type' => 'integer']);
                app(AuditService::class)->record('setting', $row->id, 'setting.updated', ['key' => $key, 'old_value' => $old, 'new_value' => $value], $actor);
            }
            DB::afterCommit(function () use ($keys): void {
                foreach ($keys as $key) {
                    $this->invalidate($key);
                }
            });
        });
    }

    public function placementPriceMinorUnits(): ?int
    {
        return $this->nullableInteger(self::PLACEMENT_PRICE_MINOR_UNITS);
    }

    public function extensionPriceMinorUnits(): ?int
    {
        return $this->nullableInteger(self::EXTENSION_PRICE_MINOR_UNITS);
    }

    public function placementDurationDays(): int
    {
        return $this->requiredInteger(self::PLACEMENT_DURATION_DAYS);
    }

    public function expiryWarningDays(): int
    {
        return $this->requiredInteger(self::EXPIRY_WARNING_DAYS);
    }

    public function expiredExtensionWindowDays(): int
    {
        return $this->requiredInteger(self::EXPIRED_EXTENSION_WINDOW_DAYS);
    }

    public function participantApplicationRetentionMonths(): int
    {
        return $this->requiredInteger(self::PARTICIPANT_APPLICATION_RETENTION_MONTHS);
    }

    public function passwordSetupLinkTtlHours(): int
    {
        return $this->requiredInteger(self::PASSWORD_SETUP_LINK_TTL_HOURS);
    }

    public function invalidate(string $key): void
    {
        Cache::forget($this->cacheKey($key));
    }

    private function requiredInteger(string $key): int
    {
        $value = $this->nullableInteger($key);

        if ($value === null) {
            throw new DomainException("Setting [{$key}] is not configured.");
        }

        return $value;
    }

    private function nullableInteger(string $key): ?int
    {
        $read = function () use ($key): array {
            $model = Setting::query()->where('key', $key)->first();

            return [
                'exists' => $model !== null,
                'type' => $model?->type,
                'value' => $model?->value,
            ];
        };
        $setting = DB::transactionLevel() > 0 ? $read() : Cache::rememberForever($this->cacheKey($key), $read);

        if (! $setting['exists']) {
            throw new DomainException("Setting [{$key}] does not exist.");
        }

        if ($setting['type'] !== 'integer') {
            throw new DomainException("Setting [{$key}] must be an integer.");
        }

        if ($setting['value'] === null) {
            return null;
        }

        if (filter_var($setting['value'], FILTER_VALIDATE_INT) === false) {
            throw new DomainException("Setting [{$key}] contains an invalid integer.");
        }

        return (int) $setting['value'];
    }

    private function cacheKey(string $key): string
    {
        return "settings.{$key}";
    }
}
