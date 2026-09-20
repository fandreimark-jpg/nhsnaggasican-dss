<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Performance audit" pass — activity_logs is the fastest-growing table
 * (1,414 rows after one pilot term) and both of its live read patterns
 * were full-table scans (EXPLAIN: type ALL + filesort):
 *
 *  - ORDER BY created_at DESC — Admin > Activity Logs (paginated) and the
 *    Admin dashboard's "recent activity" list (LIMIT 10);
 *  - WHERE action = 'login' ... DISTINCT user_id — the Admin dashboard's
 *    Data Health "accounts that have never logged in" check.
 *
 * Two plain secondary indexes, nothing else: no rows touched, no
 * constraints changed, rollback drops exactly what was added. Guarded so
 * a partially-applied MySQL DDL can be re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (!$this->hasIndex('activity_logs', 'activity_logs_created_at_index')) {
                $table->index('created_at', 'activity_logs_created_at_index');
            }
            if (!$this->hasIndex('activity_logs', 'activity_logs_action_user_id_index')) {
                $table->index(['action', 'user_id'], 'activity_logs_action_user_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if ($this->hasIndex('activity_logs', 'activity_logs_created_at_index')) {
                $table->dropIndex('activity_logs_created_at_index');
            }
            if ($this->hasIndex('activity_logs', 'activity_logs_action_user_id_index')) {
                $table->dropIndex('activity_logs_action_user_id_index');
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn(array $i) => $i['name'] === $index);
    }
};
