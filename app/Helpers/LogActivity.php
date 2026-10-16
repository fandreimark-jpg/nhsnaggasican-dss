<?php

namespace App\Helpers;

use App\Models\ActivityLog;

class LogActivity
{
    public static function log(
        string $action,
        string $description,
        ?string $tableName = null,
        ?int $recordId = null,
        ?int $userId = null,   // the acting user when it is not the logged-in one (a service called with an explicit actor)
    ): void {
        ActivityLog::create([
            'user_id'    => $userId ?? auth()->id(),
            'action'     => $action,
            'table_name' => $tableName,
            'record_id'  => $recordId,
            'description'=> $description,
            'ip_address' => request()->ip(),
        ]);
    }
}