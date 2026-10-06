<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogger
{
    public function log(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, array $metadata = [], ?Request $request = null): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'created_at' => now(),
        ]);
    }
}
