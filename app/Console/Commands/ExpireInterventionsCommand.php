<?php

namespace App\Console\Commands;

use App\Models\Intervention;
use App\Services\InterventionScheduleService;
use Illuminate\Console\Command;

/**
 * Persists "Expired — Not completed" for every approved, scheduled
 * intervention whose deadline has passed with no completion recorded.
 * Scheduled every minute in routes/console.php (needs `php artisan
 * schedule:work`, or the OS scheduler running `php artisan schedule:run`
 * each minute). Safe to rerun and catches up after downtime; pages already
 * SHOW an overdue intervention as expired even before this runs.
 */
class ExpireInterventionsCommand extends Command
{
    protected $signature = 'dss:expire-interventions {--dry-run : List what would expire, change nothing}';

    protected $description = 'Mark overdue, uncompleted interventions as Expired (bounded batches, idempotent)';

    public function handle(InterventionScheduleService $service): int
    {
        if (!Intervention::schedulingAvailable()) {
            $this->warn('Intervention scheduling is not installed on this database yet (migration 2026_10_06_000001 pending). Nothing to do.');

            return self::SUCCESS;
        }
        if ($this->option('dry-run')) {
            $due = Intervention::whereIn('status', array_diff(Intervention::OPEN_STATUSES, [Intervention::STATUS_RECOMMENDED]))
                ->whereNotNull('decided_by')->whereNotNull('deadline_at')->where('deadline_at', '<', now())
                ->whereNull('delivered_at')->whereNull('completed_at')->orderBy('id')->get(['id', 'deadline_at']);
            $this->line($due->count() . ' intervention(s) would expire' . ($due->isEmpty() ? '.' : ': ' . $due->map(fn($i) => "#{$i->id} (deadline {$i->deadline_at->format('Y-m-d H:i')})")->implode(', ')));

            return self::SUCCESS;
        }

        $count = $service->expireOverdue();
        $this->line("Expired {$count} intervention(s).");

        return self::SUCCESS;
    }
}
