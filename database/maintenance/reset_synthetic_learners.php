<?php

/**
 * Controlled removal of the REVIEWED synthetic learners (2026-10-06 inventory)
 * and their learner-specific records, before the real 50-learner upload.
 *
 *   php database/maintenance/reset_synthetic_learners.php --database=<name> [--assessments=none|all|<id,id>] [--execute]
 *
 * Without --execute it is a DRY RUN: it prints per-table counts and changes
 * nothing. With --execute everything runs in ONE transaction (InnoDB); any
 * failed check rolls the whole thing back.
 *
 * Scope is the explicit reviewed LRN list below, checked against the stored
 * names before anything runs: a learner that no longer matches, or a target
 * learner whose records reach outside the reviewed scope, stops the run.
 *
 * Deleted (only rows of the target learners):
 *   interventions, academic_concerns, assessment_evidence_audits,
 *   assessment_learner_statuses, assessment_scores, grades, risk_results,
 *   attendance_records, student_enrollments, students.
 * Optional --assessments: the listed assessment definitions (+ their
 * manual-entry assessment_uploads stubs, their evidence audits, and the
 * Add-Assessment double-submit cache keys). Default: none (kept).
 *
 * Never touched: users, sections, subjects, catalog/weights/shares,
 * teaching_assignments (+history), academic_years/terms, transmutation_ranges,
 * migrations, activity_logs (administrative history is kept whole).
 *
 * Deliberately raw query-builder deletes: model events would write NEW
 * evidence-history rows for the learners being removed, and
 * ProtectsAcademicHistory (correctly) refuses a learner with records. No
 * foreign-key checks are disabled — the order below satisfies every FK.
 */

$repo = dirname(__DIR__, 2);
require $repo . '/vendor/autoload.php';
$app = require $repo . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$opts = getopt('', ['database:', 'assessments::', 'execute']);
$database = $opts['database'] ?? null;
if (!$database) {
    fwrite(STDERR, "--database=<name> is required (no default, so the target is always explicit)\n");
    exit(2);
}
$execute = array_key_exists('execute', $opts);
$assessmentOpt = $opts['assessments'] ?? 'none';

config(['database.connections.mysql.database' => $database]);
DB::purge('mysql');
$connected = DB::selectOne('select database() as d')->d;
if ($connected !== $database) {
    fwrite(STDERR, "Connected to {$connected}, not {$database}. Stopping.\n");
    exit(2);
}

// --- The reviewed scope (LRN => "LAST, First", section) -------------------------------
const REVIEWED = [
    // Curie (section 2), created by script 2026-09-30 09:59:56, "DEMO" surname prefix.
    '991126000001' => ['DEMOALVAREZ', 'Adrian', 2], '991126000002' => ['DEMOMENDOZA', 'Gabriel', 2],
    '991126000003' => ['DEMORAMOS', 'Miguel', 2], '991126000004' => ['DEMOGARCIA', 'Sofia', 2],
    '991126000005' => ['DEMOVILLANUEVA', 'Isabel', 2],
    // Agila (section 3), created by script 2026-09-27 18:42:07; "synthetic learners" (FINAL_DEMO_AUDIT §H).
    '991300000001' => ['BERNARDO', 'NICOLE', 3], '991300000002' => ['CABRAL', 'ANDRE', 3],
    '991300000003' => ['DIZON', 'FRANCES', 3], '991300000004' => ['ESTRELLA', 'MARCO', 3],
    '991300000005' => ['FERNANDEZ', 'KYLA', 3], '991300000006' => ['GALANG', 'VINCENT', 3],
    '991300000007' => ['HILARIO', 'JASMINE', 3], '991300000008' => ['ILAGAN', 'RENZO', 3],
    '991300000009' => ['LACSON', 'DANICA', 3], '991300000010' => ['MAGSINO', 'KYLE', 3],
    '991300000011' => ['NAVARRO', 'AIRA', 3], '991300000012' => ['ORTEGA', 'SEAN', 3],
];

$stop = function (string $why) {
    fwrite(STDERR, "STOP: {$why}\n");
    exit(1);
};

$learners = DB::table('students')->whereIn('lrn', array_keys(REVIEWED))->get()->keyBy('lrn');
foreach (REVIEWED as $lrn => [$last, $first, $section]) {
    $s = $learners->get($lrn);
    if (!$s) {
        echo "note: {$lrn} {$last} is already absent\n";
        continue;
    }
    if ($s->last_name !== $last || $s->first_name !== $first || (int) $s->section_id !== $section) {
        $stop("{$lrn} no longer matches the reviewed record ({$s->last_name}, {$s->first_name}, section {$s->section_id})");
    }
}
$ids = $learners->pluck('id')->all();

// Assessment definitions to remove (optional).
$allIds = DB::table('assessments')->pluck('id')->all();
$assessmentIds = match (true) {
    $assessmentOpt === 'none' => [],
    $assessmentOpt === 'all'  => $allIds,
    default                   => array_map('intval', explode(',', $assessmentOpt)),
};
if ($missing = array_diff($assessmentIds, $allIds)) {
    $stop('unknown assessment id(s): ' . implode(',', $missing));
}
if ($assessmentIds !== []) {
    // Every score/marker on a removed definition must belong to a target learner.
    foreach (['assessment_scores', 'assessment_learner_statuses'] as $t) {
        $outside = DB::table($t)->whereIn('assessment_id', $assessmentIds)->whereNotIn('student_id', $ids ?: [0])->count();
        if ($outside > 0) {
            $stop("{$t}: {$outside} row(s) of a non-target learner on a definition to remove");
        }
    }
}

// --- The plan: [table, query] in FK-safe order ----------------------------------------
$in = $ids ?: [0];
$plan = [
    ['interventions',               fn() => DB::table('interventions')->whereIn('student_id', $in)],
    ['academic_concerns',           fn() => DB::table('academic_concerns')->whereIn('student_id', $in)],
    ['assessment_evidence_audits',  fn() => DB::table('assessment_evidence_audits')->whereIn('student_id', $in)],
    ['assessment_learner_statuses', fn() => DB::table('assessment_learner_statuses')->whereIn('student_id', $in)],
    ['assessment_scores',           fn() => DB::table('assessment_scores')->whereIn('student_id', $in)],
    ['grades',                      fn() => DB::table('grades')->whereIn('student_id', $in)],
    ['risk_results',                fn() => DB::table('risk_results')->whereIn('student_id', $in)],
    ['attendance_records',          fn() => DB::table('attendance_records')->whereIn('student_id', $in)],
    ['student_enrollments',         fn() => DB::table('student_enrollments')->whereIn('student_id', $in)],
    ['students',                    fn() => DB::table('students')->whereIn('id', $in)],
];
if ($assessmentIds !== []) {
    $uploadIds = DB::table('assessments')->whereIn('id', $assessmentIds)->pluck('import_batch_id')->filter(fn($v) => ctype_digit((string) $v))->map(fn($v) => (int) $v)->all();
    // An upload stub is removed only if no KEPT assessment still points at it.
    $keptUploads = DB::table('assessments')->whereNotIn('id', $assessmentIds)->pluck('import_batch_id')->map(fn($v) => (int) $v)->all();
    $uploadIds = array_values(array_diff($uploadIds, $keptUploads));
    // Rows of target learners are already in the learner step above.
    $plan[] = ['assessment_evidence_audits (by definition)', fn() => DB::table('assessment_evidence_audits')->whereIn('assessment_id', $assessmentIds)->whereNotIn('student_id', $in)];
    $plan[] = ['assessments', fn() => DB::table('assessments')->whereIn('id', $assessmentIds)];
    $plan[] = ['assessment_uploads (manual-entry stubs)', fn() => DB::table('assessment_uploads')->whereIn('id', $uploadIds ?: [0])->where('status', 'manual_entry')];
    $plan[] = ['cache (Add-Assessment double-submit keys)', fn() => DB::table('cache')->where('key', 'like', '%assessment-%-submission:%')];
}

// Anything of a target learner in a table the plan does not cover would stop here.
$learnerColumns = DB::select("SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = ? AND column_name = 'student_id'", [$database]);
$planned = array_map(fn($p) => explode(' ', $p[0])[0], $plan);
foreach ($learnerColumns as $col) {
    if (!in_array($col->t, $planned, true) && DB::table($col->t)->whereIn('student_id', $in)->exists()) {
        $stop("{$col->t}.student_id references a target learner but is not in the plan");
    }
}

printf("%s on database %s — %d target learner(s), %d assessment definition(s) to remove\n",
    $execute ? 'EXECUTE' : 'DRY RUN', $database, count($ids), count($assessmentIds));
$counts = [];
foreach ($plan as [$label, $query]) {
    $counts[$label] = $query()->count();
    printf("  %-46s %5d\n", $label, $counts[$label]);
}
$kept = ['users', 'sections', 'subjects', 'teaching_assignments', 'teaching_assignment_history', 'academic_years', 'academic_terms',
    'transmutation_ranges', 'migrations', 'deped_subject_catalog', 'subject_group_weights', 'exam_role_shares', 'subject_terms', 'activity_logs'];
$checksum = fn() => collect(DB::select('CHECKSUM TABLE ' . implode(', ', $kept)))->mapWithKeys(fn($r) => [$r->Table => $r->Checksum])->all();
$before = $checksum();

if (!$execute) {
    echo "Dry run only — nothing changed.\n";
    exit(0);
}

DB::transaction(function () use ($plan, $counts) {
    foreach ($plan as [$label, $query]) {
        $deleted = $query()->delete();
        if ($deleted !== $counts[$label]) {
            throw new RuntimeException("{$label}: deleted {$deleted}, expected {$counts[$label]} — rolled back");
        }
    }
    // Orphan checks inside the transaction: any failure rolls everything back.
    $orphans = [
        'assessment_scores without student'    => 'SELECT COUNT(*) n FROM assessment_scores x LEFT JOIN students s ON s.id = x.student_id WHERE s.id IS NULL',
        'markers without student'              => 'SELECT COUNT(*) n FROM assessment_learner_statuses x LEFT JOIN students s ON s.id = x.student_id WHERE s.id IS NULL',
        'grades without student'               => 'SELECT COUNT(*) n FROM grades x LEFT JOIN students s ON s.id = x.student_id WHERE s.id IS NULL',
        'concerns without student'             => 'SELECT COUNT(*) n FROM academic_concerns x LEFT JOIN students s ON s.id = x.student_id WHERE s.id IS NULL',
        'interventions without student'        => 'SELECT COUNT(*) n FROM interventions x LEFT JOIN students s ON s.id = x.student_id WHERE s.id IS NULL',
        'interventions with a missing concern' => 'SELECT COUNT(*) n FROM interventions x LEFT JOIN academic_concerns c ON c.id = x.academic_concern_id WHERE x.academic_concern_id IS NOT NULL AND c.id IS NULL',
        'enrollments without student'          => 'SELECT COUNT(*) n FROM student_enrollments x LEFT JOIN students s ON s.id = x.student_id WHERE s.id IS NULL',
        'evidence audits without student'      => 'SELECT COUNT(*) n FROM assessment_evidence_audits x LEFT JOIN students s ON s.id = x.student_id WHERE s.id IS NULL',
        'evidence audits without assessment'   => 'SELECT COUNT(*) n FROM assessment_evidence_audits x LEFT JOIN assessments a ON a.id = x.assessment_id WHERE a.id IS NULL',
        'scores without assessment'            => 'SELECT COUNT(*) n FROM assessment_scores x LEFT JOIN assessments a ON a.id = x.assessment_id WHERE a.id IS NULL',
    ];
    foreach ($orphans as $label => $sql) {
        if (($n = DB::selectOne($sql)->n) > 0) {
            throw new RuntimeException("orphan check failed: {$label} = {$n} — rolled back");
        }
    }
});
App\Services\GradingEngine::invalidateEvidence();

$after = $checksum();
foreach ($before as $table => $sum) {
    if ($after[$table] !== $sum) {
        fwrite(STDERR, "WARNING: preserved table {$table} changed during the run\n");
        exit(1);
    }
}
echo "Done. Orphan checks passed; preserved tables unchanged (" . count($kept) . " checksums).\n";
