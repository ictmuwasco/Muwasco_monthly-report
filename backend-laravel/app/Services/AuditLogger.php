<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * AuditLogger — writes append-only entries to `audit_logs`.
 *
 * Never records passwords or tokens; callers must pass sanitized values.
 */
class AuditLogger
{
    /**
     * Record an audit entry.
     *
     * @param  string      $action  e.g. 'user.created'
     * @param  Model|null  $entity  the affected model
     * @param  array|null  $old     old attribute values (sanitized)
     * @param  array|null  $new     new attribute values (sanitized)
     */
    public static function record(
        string $action,
        ?Model $entity = null,
        ?array $old = null,
        ?array $new = null,
    ): void {
        $request = request();

        AuditLog::create([
            'actor_user_id' => Auth::id(),
            'action'        => $action,
            'entity_type'   => $entity ? $entity::class : null,
            'entity_id'     => $entity?->getKey(),
            'old_values'    => $old,
            'new_values'    => $new,
            'ip_address'    => $request?->ip(),
            'user_agent'    => substr((string) $request?->userAgent(), 0, 255),
        ]);
    }

    /**
     * Strip sensitive keys from audit payloads.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function sanitize(array $attributes): array
    {
        unset($attributes['password'], $attributes['password_confirmation'], $attributes['token']);

        return $attributes;
    }
}