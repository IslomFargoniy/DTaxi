<?php

namespace App\Models\Concerns;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Geometry;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Panel orqali o'zgarishlarni audit jurnaliga yozish (§10.7). Faqat foydalanuvchi harakatlari (konsol/daemon emas).
 * Maxfiy maydonlar qiymati yozilmaydi — faqat "o'zgardi" belgisi.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => self::writeAudit($model, 'created'));
        static::updated(fn (Model $model) => self::writeAudit($model, 'updated'));
        static::deleted(fn (Model $model) => self::writeAudit($model, 'deleted'));
    }

    private static function writeAudit(Model $model, string $action): void
    {
        $user = Auth::user();
        // Haydovchi ilovasidagi harakatlar (holat, joylashuv) audit emas
        if (! $user instanceof User || $user->hasRole(Role::Driver)) {
            return;
        }

        $ignored = ['updated_at', 'created_at', 'remember_token', 'operator_status_at', 'last_seen_at', 'last_location', 'balance', 'status', 'rating', 'mock_location_at', 'sip_extension'];
        $secret = ['password', 'credentials', 'sip_password', 'two_factor_secret', 'two_factor_recovery_codes'];

        $changes = [];
        if ($action === 'updated') {
            foreach (array_keys($model->getChanges()) as $key) {
                if (in_array($key, $ignored, true)) {
                    continue;
                }
                $from = $model->getOriginal($key);
                $to = $model->getAttribute($key);
                $changes[$key] = match (true) {
                    in_array($key, $secret, true) => '••• (changed)',
                    // Hudud poligoni minglab koordinata — jurnalga faqat belgi
                    self::isArea($from) || self::isArea($to) => 'Map area changed',
                    default => ['from' => self::auditValue($from), 'to' => self::auditValue($to)],
                };
            }
            if ($changes === []) {
                return;
            }
        }

        AuditLog::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'action' => $action,
            'subject_type' => class_basename($model),
            'subject_id' => (string) $model->getKey(),
            'subject_label' => self::auditLabel($model),
            'changes' => $changes ?: null,
            'ip' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    private static function isArea(mixed $value): bool
    {
        return $value instanceof Geometry && ! $value instanceof Point;
    }

    private static function auditValue(mixed $value): mixed
    {
        return $value instanceof Point
            ? round($value->getLatitude(), 6).', '.round($value->getLongitude(), 6)
            : $value;
    }

    private static function auditLabel(Model $model): ?string
    {
        foreach (['name', 'title', 'key', 'number', 'callsign', 'provider'] as $field) {
            $value = $model->getAttribute($field);
            if (is_scalar($value) && $value !== '') {
                return (string) $value;
            }
            if ($value instanceof \BackedEnum) {
                return (string) $value->value;
            }
        }

        return null;
    }
}
