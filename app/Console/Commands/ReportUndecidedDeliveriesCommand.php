<?php

namespace App\Console\Commands;

use App\Models\Intervention;
use Illuminate\Console\Command;

/**
 * "Correctness and interface pass" TASK 2d — a one-off, read-only report
 * for the interventions that were acknowledged and/or delivered BEFORE
 * TASK 2a's guard existed, i.e. while still at status 'recommended' with
 * no decided_by — the Principal's decision step was skipped for these.
 *
 * This command only prints. It never updates a row, never invalidates a
 * delivery, never changes status — see CLAUDE.md's database safety rules
 * and this task's own instruction: "It must only print. It must not
 * modify any row." What to do about the rows it lists (retroactively
 * decide them, leave them as historical record, something else) is a
 * decision for the user, not this command.
 */
class ReportUndecidedDeliveriesCommand extends Command
{
    protected $signature = 'dss:report-undecided-deliveries';

    protected $description = 'List interventions that were acknowledged or delivered without a recorded Principal decision. Read-only — prints only, never modifies a row.';

    public function handle(): int
    {
        $rows = Intervention::with(['student', 'subject'])
            ->where(function ($q) {
                $q->where('status', Intervention::STATUS_RECOMMENDED)
                  ->orWhereNull('decided_by');
            })
            ->where(function ($q) {
                $q->whereNotNull('acknowledged_at')
                  ->orWhereNotNull('delivered_at');
            })
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No interventions were acknowledged or delivered without a recorded Principal decision.');
            return self::SUCCESS;
        }

        $this->warn($rows->count() . ' intervention(s) were acknowledged and/or delivered while still awaiting a Principal decision:');

        $this->table(
            ['ID', 'Student', 'Subject', 'Term', 'Status', 'Acknowledged At', 'Delivered At'],
            $rows->map(fn(Intervention $iv) => [
                $iv->id,
                $iv->student ? $iv->student->last_name . ', ' . $iv->student->first_name : '—',
                $iv->subject->name ?? '—',
                $iv->grading_period ?? '—',
                $iv->status,
                $iv->acknowledged_at?->format('Y-m-d H:i') ?? '—',
                $iv->delivered_at?->format('Y-m-d H:i') ?? '—',
            ])->all()
        );

        $this->line('');
        $this->line('This report is read-only — nothing was changed. Decide case by case what, if anything, to do with these.');

        return self::SUCCESS;
    }
}
