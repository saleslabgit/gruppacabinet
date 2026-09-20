<?php

namespace App\Services;

use App\Models\Setting;
use DomainException;
use Illuminate\Support\Facades\Cache;

class SettingService
{
    public const PLACEMENT_PRICE_MINOR_UNITS = 'placement_price_minor_units';

    public const EXTENSION_PRICE_MINOR_UNITS = 'extension_price_minor_units';

    public const PLACEMENT_DURATION_DAYS = 'placement_duration_days';

    public const EXPIRY_WARNING_DAYS = 'expiry_warning_days';

    public const EXPIRED_EXTENSION_WINDOW_DAYS = 'expired_extension_window_days';

    public const PARTICIPANT_APPLICATION_RETENTION_MONTHS = 'participant_application_retention_months';

    public const PASSWORD_SETUP_LINK_TTL_HOURS = 'password_setup_link_ttl_hours';

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
        /** @var array{exists: bool, type: string|null, value: string|null} $setting */
        $setting = Cache::rememberForever($this->cacheKey($key), function () use ($key): array {
            $model = Setting::query()->where('key', $key)->first();

            return [
                'exists' => $model !== null,
                'type' => $model?->type,
                'value' => $model?->value,
            ];
        });

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
