<?php

/**
 * Small commands for browser scenarios, against the ISOLATED database only
 * (same apply/assert as router.php — it refuses anything else):
 *
 *   php tests/Browser/isolated-artisan.php seed-interventions   -> JSON ids
 *   php tests/Browser/isolated-artisan.php intervention-for-concern <concern> -> id
 *   php tests/Browser/isolated-artisan.php shift <id> <minutes> -> moves the
 *       intervention's start and deadline back <minutes> (simulates time
 *       passing without changing the computer's clock)
 *   php tests/Browser/isolated-artisan.php expire                -> dss:expire-interventions
 *   race-setup | race-open-new <student> <subject> <hold s> | race-reopen <id> <version> | count-open <student> <subject>
 *       -> the reopen-vs-new-open-intervention race (MySQL isolation)
 */
require __DIR__ . '/isolation.php';
browser_isolation_apply();
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
browser_isolation_assert($app);

use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;

$cmd = $argv[1] ?? '';
if ($cmd === 'seed-interventions') {
    $principal = User::where('email', 'principal@browser.test')->firstOrFail();
    $agila = Section::where('name', 'Agila')->firstOrFail();
    $make = function (string $last, string $subject) use ($principal, $agila) {
        $student = Student::where('last_name', $last)->firstOrFail();
        return Intervention::create(['student_id' => $student->id, 'subject_id' => Subject::where('name', 'like', $subject . '%')->firstOrFail()->id,
            'section_id' => $agila->id, 'school_year' => $agila->school_year, 'grading_period' => 1, 'recommended_type' => 'remediation',
            'status' => 'approved', 'origin' => 'principal', 'created_by' => $principal->id, 'decided_by' => $principal->id, 'decided_at' => now()]);
    };
    $first = $make('ABAD', 'Community Engagement');
    // As Principal\InterventionController::store() does: the section adviser (Bong) is assigned.
    (new App\Services\InterventionScheduleService())->recordAutoAssignment($first, User::findOrFail($agila->adviser_id), $principal);
    // Carla (subject teacher) teaches CESC in Curie: she keeps her Class Record, but has no intervention access.
    $curie = Section::where('name', 'Curie')->firstOrFail();
    App\Models\TeachingAssignment::firstOrCreate(['section_id' => $curie->id, 'subject_id' => $first->subject_id, 'school_year' => $curie->school_year, 'grading_period' => 1],
        ['user_id' => User::where('email', 'carla@browser.test')->value('id')]);
    // Ben: a Missing requirement in General Science -> an open concern; the Principal records the intervention in the browser.
    $gs = Subject::where('name', 'like', 'General Science%')->firstOrFail();
    $item = App\Models\Assessment::create(['subject_id' => $gs->id, 'section_id' => $agila->id, 'grading_period' => 1, 'school_year' => $agila->school_year,
        'name' => 'GS Quiz (intervention flow)', 'assessment_type' => 'GS Quiz', 'component' => 'written_work', 'max_score' => 10, 'uploaded_by' => $principal->id]);
    $ben = Student::where('last_name', 'BAUTISTA')->firstOrFail();
    (new App\Services\AssessmentStatusService())->markMissing($item, $ben->id, User::where('email', 'darien@browser.test')->value('id'));
    (new App\Services\AcademicMonitoringService())->evaluate($agila->school_year, 1);
    $concern = App\Models\AcademicConcern::where('student_id', $ben->id)->where('status', 'open')->firstOrFail();
    echo json_encode(['first' => $first->id, 'concern' => $concern->id, 'item' => $item->id, 'curie' => $curie->id, 'cesc' => $first->subject_id]), "\n";
} elseif ($cmd === 'intervention-for-concern') {
    echo (int) Intervention::where('academic_concern_id', (int) $argv[2])->value('id'), "\n";
} elseif ($cmd === 'seed-report-grades') {
    // Every expected Agila Term 1 grade, verified (report submission needs them all encoded).
    $agila = Section::where('name', 'Agila')->firstOrFail();
    $bong = User::where('email', 'bong@browser.test')->firstOrFail();
    $n = 0;
    foreach ((new App\Services\SectionElectiveStatus())->expectedSubjectsForTerm($agila, 1) as $subject) {
        foreach (Student::enrolledIn($agila)->orderBy('last_name')->get() as $i => $student) {
            App\Models\Grade::updateOrCreate(['student_id' => $student->id, 'subject_id' => $subject->id, 'grading_period' => 1, 'school_year' => $agila->school_year],
                ['section_id' => $agila->id, 'grade' => 76 + ($i % 20), 'is_verified' => true, 'is_provisional' => false, 'encoded_by' => $bong->id]);
            $n++;
        }
    }
    echo "grades: {$n}\n";
} elseif ($cmd === 'set-grade') {
    // set-grade <last name> <value>: changes that learner's first Agila Term 1 grade (simulates a later correction).
    $student = Student::where('last_name', $argv[2])->firstOrFail();
    $g = App\Models\Grade::where('student_id', $student->id)->where('grading_period', 1)->orderBy('subject_id')->firstOrFail();
    $g->update(['grade' => (float) $argv[3]]);
    echo "set {$argv[2]} subject {$g->subject_id} = {$argv[3]}\n";
} elseif ($cmd === 'shift') {
    $iv = Intervention::findOrFail((int) $argv[2]);
    $m = (int) $argv[3];
    Intervention::whereKey($iv->id)->update(['starts_at' => $iv->starts_at->copy()->subMinutes($m), 'deadline_at' => $iv->deadline_at->copy()->subMinutes($m)]);
    echo "shifted #{$iv->id} back {$m} min: deadline now " . Intervention::find($iv->id)->deadline_at->format('Y-m-d H:i') . "\n";
} elseif ($cmd === 'migrate-check') {
    // Rollback + re-apply ONLY the scheduling migration, on the isolated DB.
    $path = 'database/migrations/2026_10_06_000001_add_scheduling_to_interventions.php';
    foreach ([['migrate:rollback', ['--path' => $path, '--force' => true]], ['migrate', ['--path' => $path, '--force' => true]]] as [$c, $args]) {
        Illuminate\Support\Facades\Artisan::call($c, $args);
        echo "{$c}: " . trim(preg_replace('/\s+/', ' ', Illuminate\Support\Facades\Artisan::output())) . "\n";
    }
    echo 'tables: ' . json_encode([Illuminate\Support\Facades\Schema::hasTable('intervention_events'), Illuminate\Support\Facades\Schema::hasColumn('interventions', 'deadline_at')]) . "\n";
} elseif ($cmd === 'race-setup') {
    // An EXPIRED intervention for ABAD / CESC (responsible: Bong, who set the schedule himself).
    $svc = new App\Services\InterventionScheduleService();
    $principal = User::where('email', 'principal@browser.test')->firstOrFail();
    $bong = User::where('email', 'bong@browser.test')->firstOrFail();
    $agila = Section::where('name', 'Agila')->firstOrFail();
    $student = Student::where('last_name', 'ABAD')->firstOrFail();
    $subject = Subject::where('name', 'like', 'Community Engagement%')->firstOrFail();
    $iv = Intervention::create(['student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $agila->id, 'school_year' => $agila->school_year,
        'grading_period' => 1, 'recommended_type' => 'remediation', 'status' => 'approved', 'origin' => 'principal', 'created_by' => $principal->id, 'decided_by' => $principal->id, 'decided_at' => now()]);
    $svc->transferToSectionAdviser($iv, $principal, 'race setup: the Agila adviser');
    $svc->schedule($iv, ['title' => 'Race', 'starts_at' => now()->addMinute(), 'deadline_at' => now()->addHour()], $bong, 1);
    Intervention::whereKey($iv->id)->update(['starts_at' => now()->subHours(3), 'deadline_at' => now()->subHours(2)]);
    $svc->expireOverdue();
    echo json_encode(['iv' => $iv->id, 'version' => $iv->fresh()->schedule_version, 'student' => $student->id, 'subject' => $subject->id, 'status' => $iv->fresh()->status]), "\n";
} elseif ($cmd === 'race-open-new') {
    // Mimics Principal\InterventionController::store(): lock the learner, insert a new OPEN intervention, hold the transaction.
    [$studentId, $subjectId, $hold] = [(int) $argv[2], (int) $argv[3], (int) $argv[4]];
    $principal = User::where('email', 'principal@browser.test')->firstOrFail();
    Illuminate\Support\Facades\DB::transaction(function () use ($studentId, $subjectId, $hold, $principal) {
        Student::whereKey($studentId)->lockForUpdate()->first();
        $open = Intervention::where('student_id', $studentId)->where('subject_id', $subjectId)->where('grading_period', 1)->whereIn('status', Intervention::OPEN_STATUSES)->exists();
        if ($open) { echo "store: refused (an open one exists)\n"; return; }
        $new = Intervention::create(['student_id' => $studentId, 'subject_id' => $subjectId, 'grading_period' => 1, 'school_year' => '2026-2027', 'section_id' => Student::find($studentId)->section_id,
            'recommended_type' => 'remediation', 'status' => 'approved', 'origin' => 'principal', 'created_by' => $principal->id, 'decided_by' => $principal->id, 'decided_at' => now()]);
        echo "store: inserted #{$new->id}, holding {$hold}s\n";
        sleep($hold);
    });
    echo "store: committed\n";
} elseif ($cmd === 'race-reopen') {
    // The responsible staff (Bong) reopens the expired intervention directly: race-reopen <id> <version>.
    $bong = User::where('email', 'bong@browser.test')->firstOrFail();
    $iv = Intervention::findOrFail((int) $argv[2]);
    try {
        (new App\Services\InterventionScheduleService())->reopen($iv, $bong, now()->addMinute(), now()->addDay(), 'Race test reopen', (int) $argv[3]);
        echo "reopen: APPLIED\n";
    } catch (App\Services\InterventionTransitionException $e) {
        echo 'reopen: refused — ' . $e->getMessage() . "\n";
    }
} elseif ($cmd === 'count-open') {
    echo 'open for learner/subject/term: ' . Intervention::where('student_id', (int) $argv[2])->where('subject_id', (int) $argv[3])->where('grading_period', 1)->whereIn('status', Intervention::OPEN_STATUSES)->count() . "\n";
} elseif ($cmd === 'expire') {
    Illuminate\Support\Facades\Artisan::call('dss:expire-interventions');
    echo trim(Illuminate\Support\Facades\Artisan::output()), "\n";
} else {
    fwrite(STDERR, "usage: seed-interventions | shift <id> <minutes> | expire\n");
    exit(2);
}
