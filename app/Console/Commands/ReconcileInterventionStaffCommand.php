<?php

namespace App\Console\Commands;

use App\Models\Intervention;
use App\Services\InterventionScheduleService;
use App\Services\InterventionTransitionException;
use Illuminate\Console\Command;

/**
 * Responsibility reconciliation (2026-10-07): actionable interventions
 * (not completed/cancelled) whose responsible staff is not their section's
 * current active adviser — e.g. a subject teacher named under the former
 * rule, or a replaced adviser. DRY RUN by default: lists every case and
 * changes nothing. --apply transfers each valid one through
 * InterventionScheduleService::transferToSectionAdviser() (locked, versioned,
 * one history event each; schedules, history and the previous staff's actions
 * are kept). Back up the database before --apply.
 */
class ReconcileInterventionStaffCommand extends Command
{
    protected $signature = 'dss:reconcile-intervention-staff {--apply : Perform the valid transfers (default: dry run, nothing written)}';

    protected $description = 'List (or, with --apply, fix) interventions whose responsible staff is not the section adviser';

    public function handle(InterventionScheduleService $service): int
    {
        if (!Intervention::schedulingAvailable()) {
            $this->warn('Intervention scheduling is not installed on this database. Nothing to do.');

            return self::SUCCESS;
        }
        $rows = $service->needingTransfer();
        $apply = (bool) $this->option('apply');
        $this->line(($apply ? 'APPLY' : 'DRY RUN — nothing is written') . ': ' . $rows->count() . ' actionable intervention(s) need attention.');
        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        $table = [];
        $moved = 0;
        foreach ($rows as $iv) {
            [$adviser, $problem] = $service->adviserFor($iv);
            $pending = $iv->scheduleRequests->where('status', 'pending');
            $outcome = $adviser ? 'transfer valid' : 'BLOCKED: ' . $problem;
            if ($apply && $adviser) {
                try {
                    $moved += $service->transferToSectionAdviser($iv, null, 'Reconciliation: responsibility moved to the section adviser (dss:reconcile-intervention-staff).') ? 1 : 0;
                    $outcome = 'transferred';
                } catch (InterventionTransitionException $e) {
                    $outcome = 'BLOCKED: ' . $e->getMessage();
                }
            }
            $current = $iv->responsibleUser;
            $table[] = [
                $iv->id, $iv->lifecycle(),
                trim(($iv->student?->last_name ?? '?') . ', ' . ($iv->student?->first_name ?? '')),
                ($iv->section ? 'G' . $iv->section->grade_level . ' ' . $iv->section->name : '—') . ' / ' . ($iv->subject?->name ?? '—') . ' / T' . $iv->grading_period . ' ' . $iv->school_year,
                $current ? $current->name . ' (' . $current->role . ($current->isActive() ? '' : ', inactive') . ')' : 'none',
                $adviser?->name ?? '—',
                $outcome,
                $pending->isEmpty() ? '0' : $pending->count() . ' (' . $pending->pluck('type')->unique()->implode(', ') . ')',
            ];
        }
        $this->table(['ID', 'State', 'Learner', 'Section / Subject / Term', 'Responsible now', 'Section adviser', 'Outcome', 'Pending requests'], $table);
        if ($apply) {
            $this->line("Transferred {$moved} intervention(s).");
        }

        return self::SUCCESS;
    }
}
