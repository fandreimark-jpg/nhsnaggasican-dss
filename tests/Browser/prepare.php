<?php

/**
 * Builds the ISOLATED browser-test database from scratch:
 *
 *   php tests/Browser/prepare.php
 *
 * A fresh SQLite file under storage/framework/browser-testing, migrated and
 * seeded with a small, synthetic school. The destructive step (migrate:fresh)
 * runs only after browser_isolation_assert() has proven the booted
 * application points at that file — never at MySQL or the working database.
 * Accounts use the throwaway password below; nothing here is real data.
 */

require __DIR__ . '/isolation.php';

const BROWSER_PASSWORD = 'Browser-Test-Only-1';

$root = dirname(__DIR__, 2);
browser_isolation_apply();
if (browser_isolation_mode() === 'sqlite') {
    $dbPath = browser_isolation_db_path();
    if (is_file($dbPath)) {
        unlink($dbPath);
    }
    touch($dbPath);
}

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
browser_isolation_assert($app);

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\TeachingAssignmentHistory;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

// Second, independent check right before the only destructive command.
browser_isolation_assert($app);
Artisan::call('migrate:fresh', ['--force' => true]);
// The real DepEd transmutation tables and exam shares, as production seeds them.
foreach ([\Database\Seeders\TransmutationRangesSeeder::class, \Database\Seeders\Do015TransmutationSeeder::class, \Database\Seeders\ExamRoleSharesSeeder::class] as $seeder) {
    Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
}
fwrite(STDOUT, 'Migrated isolated database: ' . (browser_isolation_mode() === 'mysql' ? 'mysql ' . BROWSER_MYSQL_DB : browser_isolation_db_path()) . "\n");

AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
AcademicTerm::ensureExistFor('2026-2027');
AcademicTerm::where('school_year', '2026-2027')->where('term', 1)->update(['is_open' => true]);

$user = function (string $role, string $last, string $first, string $email) {
    $u = new User(['name' => "{$first} {$last}", 'last_name' => $last, 'first_name' => $first, 'email' => $email, 'password' => Hash::make(BROWSER_PASSWORD)]);
    $u->role = $role;
    $u->is_active = true;
    $u->save();

    return $u;
};
$admin = $user('admin', 'Admin', 'System', 'admin@browser.test');
$principal = $user('principal', 'Santos', 'Maria', 'principal@browser.test');
$bong = $user('adviser', 'Cruz', 'Bong', 'bong@browser.test');
$darien = $user('adviser', 'Bosco', 'Darien', 'darien@browser.test');
$carla = $user('subject_teacher', 'Diaz', 'Carla', 'carla@browser.test');

$track = Track::create(['name' => 'Academic Track', 'code' => 'ACAD']);
$agila = Section::create(['name' => 'Agila', 'grade_level' => 11, 'curriculum' => 'sshs', 'track_id' => $track->id, 'adviser_id' => $bong->id, 'school_year' => '2026-2027']);
$curie = Section::create(['name' => 'Curie', 'grade_level' => 11, 'curriculum' => 'sshs', 'track_id' => $track->id, 'adviser_id' => $darien->id, 'school_year' => '2026-2027']);

$subjects = [];
foreach (['General Science', 'Community Engagement', 'General Mathematics'] as $name) {
    $s = Subject::create(['name' => $name, 'type' => 'core', 'grade_level' => 11, 'subject_group' => 'core_academic']);
    $s->syncTerms([1, 2, 3]);
    $subjects[$name] = $s;
}

$names = ['ABAD, ANA', 'BAUTISTA, BEN', 'CRUZ, CARLO', 'DELA ROSA, DINA', 'ESTRADA, ELY', 'FLORES, FE', 'GARCIA, GIO', 'HERNANDEZ, HANNA'];
foreach ($names as $i => $full) {
    [$last, $first] = array_map('trim', explode(',', $full));
    Student::create(['lrn' => sprintf('0%011d', 1000 + $i), 'last_name' => $last, 'first_name' => $first, 'gender' => $i < 4 ? 'male' : 'female', 'section_id' => $agila->id]); // MALE block first, as the template groups them
}
Student::create(['lrn' => '099999999999', 'last_name' => 'OUTSIDE', 'first_name' => 'OTTO', 'gender' => 'male', 'section_id' => $curie->id]);

// The screenshot scenario: Bong advises Agila and teaches CESC there;
// Darien teaches General Science in Agila (Term 1).
$assign = function (User $t, Section $sec, Subject $sub) use ($admin) {
    $a = TeachingAssignment::create(['user_id' => $t->id, 'section_id' => $sec->id, 'subject_id' => $sub->id, 'school_year' => '2026-2027', 'grading_period' => 1, 'assigned_by' => $admin->id]);
    TeachingAssignmentHistory::create(['teaching_assignment_id' => $a->id, 'action' => 'assigned', 'section_id' => $sec->id, 'subject_id' => $sub->id,
        'school_year' => '2026-2027', 'grading_period' => 1, 'new_user_id' => $t->id, 'changed_by' => $admin->id, 'changed_at' => now()]);
};
$assign($bong, $agila, $subjects['Community Engagement']);
$assign($darien, $agila, $subjects['General Science']);

// A LARGE class for layout checks: Narra, 45 learners, General Mathematics
// taught by Bong, 12 WW + 8 PT + 3 Examination items with deterministic
// scores, some blanks (No Evidence Yet) and some Missing marks.
// Narra's adviser exists for the concurrent-verification check (verify-race.mjs);
// teaching there is still Bong's assignment only.
$nora = $user('adviser', 'Reyes', 'Nora', 'narra@browser.test');
$narra = Section::create(['name' => 'Narra', 'grade_level' => 11, 'curriculum' => 'sshs', 'track_id' => $track->id, 'adviser_id' => $nora->id, 'school_year' => '2026-2027']);
$first = ['ADA', 'BEA', 'CAI', 'DAN', 'EVE', 'FIL', 'GIL', 'HAL', 'IAN'];
$last = ['AQUINO', 'BAUTISTA', 'CASTRO', 'DIAZ', 'ESPINO'];
$learners = [];
for ($i = 0; $i < 45; $i++) {
    $learners[] = Student::create(['lrn' => sprintf('1%011d', 5000 + $i), 'last_name' => $last[$i % 5] . ' ' . chr(65 + intdiv($i, 5)), 'first_name' => $first[$i % 9],
        'gender' => $i % 2 ? 'male' : 'female', 'section_id' => $narra->id]);
}
$assign($bong, $narra, $subjects['General Mathematics']);
$items = [];
foreach (range(1, 12) as $n) { $items[] = ['Quiz ' . $n, 'written_work', null, 10 + ($n % 4) * 5]; }
foreach (range(1, 8) as $n) { $items[] = ['Performance Task ' . $n, 'performance_task', null, 50]; }
foreach ([['Summative Test 1', 'st1'], ['Summative Test 2', 'st2'], ['Term Examination', 'term_exam']] as [$n, $role]) { $items[] = [$n, 'examination', $role, 40]; }
foreach ($items as $k => [$name, $component, $role, $max]) {
    $a = \App\Models\Assessment::create(['subject_id' => $subjects['General Mathematics']->id, 'section_id' => $narra->id, 'grading_period' => 1, 'school_year' => '2026-2027',
        'name' => $name, 'assessment_type' => $name, 'component' => $component, 'exam_role' => $role, 'max_score' => $max, 'uploaded_by' => $bong->id]);
    foreach ($learners as $j => $l) {
        $r = ($j * 7 + $k * 13) % 23;
        if ($r === 0) { continue; }                       // blank: No Evidence Yet
        if ($r === 1) {                                   // explicit Missing
            \App\Models\AssessmentLearnerStatus::create(['assessment_id' => $a->id, 'student_id' => $l->id, 'status' => 'missing', 'recorded_by' => $bong->id]);
            continue;
        }
        \App\Models\AssessmentScore::create(['assessment_id' => $a->id, 'student_id' => $l->id, 'score' => round($max * (0.45 + (($j * 3 + $k) % 11) / 20), 0)]);
    }
}

fwrite(STDOUT, "Seeded: " . User::count() . " accounts (password " . BROWSER_PASSWORD . "), " . \App\Models\Section::count() . " sections, " . \App\Models\Subject::count() . " subjects, "
    . \App\Models\Student::count() . " learners, " . \App\Models\TeachingAssignment::count() . " assignments, " . \App\Models\Assessment::count() . " assessments (incl. the large Narra fixture).\n");
