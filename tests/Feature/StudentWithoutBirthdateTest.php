<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Grade;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Student identity and term-specific subject offerings" pass, PART 1 —
 * birthdate is no longer part of the learner record. The required format
 * is lrn / last_name / first_name / middle_name / gender. STEP O items
 * 1-5: create, edit (Admin and Adviser), CSV import, ECR learner import,
 * and — the one that matters most — existing learners and their academic
 * history survive the column drop untouched.
 */
class StudentWithoutBirthdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_birthdate_column_is_gone_and_the_model_no_longer_exposes_it(): void
    {
        $this->assertFalse(Schema::hasColumn('students', 'birthdate'));
        $this->assertNotContains('birthdate', (new Student())->getFillable());
        $this->assertArrayNotHasKey('birthdate', (new Student())->getCasts());
    }

    public function test_admin_can_create_a_student_without_birthdate(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/students', [
            'lrn' => '100000000301', 'last_name' => 'Reyes', 'first_name' => 'Ana',
            'middle_name' => 'Cruz', 'gender' => 'female', 'section_id' => $section->id,
        ]);

        $response->assertRedirect(route('admin.students'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['lrn' => '100000000301', 'middle_name' => 'Cruz', 'gender' => 'female']);
    }

    public function test_a_submitted_birthdate_is_simply_ignored_not_stored_or_rejected(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $this->actingAs($admin)->post('/admin/students', [
            'lrn' => '100000000302', 'last_name' => 'Reyes', 'first_name' => 'Ana',
            'gender' => 'female', 'section_id' => $section->id, 'birthdate' => '2008-01-01',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', ['lrn' => '100000000302']);
    }

    public function test_admin_and_adviser_can_edit_a_student_without_birthdate(): void
    {
        $admin   = User::factory()->admin()->create();
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $student = Student::factory()->create(['section_id' => $section->id, 'first_name' => 'Old']);

        $this->actingAs($admin)->put('/admin/students/' . $student->id, [
            'lrn' => $student->lrn, 'last_name' => $student->last_name, 'first_name' => 'AdminEdited',
            'middle_name' => '', 'gender' => 'male', 'section_id' => $section->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame('AdminEdited', $student->fresh()->first_name);

        $this->actingAs($adviser)->put('/adviser/students/' . $student->id, [
            'last_name' => $student->last_name, 'first_name' => 'AdviserEdited', 'middle_name' => '', 'gender' => 'male',
        ])->assertSessionHasNoErrors();
        $this->assertSame('AdviserEdited', $student->fresh()->first_name);
    }

    public function test_csv_learner_import_works_with_the_five_column_format(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $csv = "lrn,last_name,first_name,middle_name,gender\n"
            . "100000000303,Dela Cruz,Juan,Santos,male\n"
            . "100000000304,Santos,Maria,,female\n";

        $this->actingAs($admin)->post('/admin/students/import', [
            'section_id' => $section->id,
            'file'       => UploadedFile::fake()->createWithContent('learners.csv', $csv),
        ])->assertRedirect(route('admin.students'));

        $this->assertDatabaseHas('students', ['lrn' => '100000000303', 'section_id' => $section->id, 'middle_name' => 'Santos']);
        $this->assertDatabaseHas('students', ['lrn' => '100000000304', 'section_id' => $section->id]);
    }

    public function test_ecr_learner_import_confirm_creates_students_without_birthdate(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create();

        // The preview step stores classified rows in the session in exactly
        // this shape (see Admin\StudentController::classifySshsRows()).
        $this->withSession(['ecr_learner_import' => [
            'section_id'      => $section->id,
            'stored_filename' => 'does-not-exist.xlsx',
            'rows'            => [
                ['status' => 'insert', 'lrn' => '100000000305', 'last_name' => 'Bautista', 'first_name' => 'Ana', 'middle_name' => 'Lopez', 'gender' => 'female', 'reason' => null],
                ['status' => 'existing', 'lrn' => '100000000306', 'last_name' => 'X', 'first_name' => 'Y', 'middle_name' => '', 'gender' => 'male', 'reason' => 'Already enrolled'],
            ],
        ]])->actingAs($admin)->post('/admin/students/import-from-ecr/confirm')
            ->assertSessionHasNoErrors()
            // Pins the STATUS too: this assertion set used to pass while the
            // request 500'd, because the import had already committed before
            // the activity-log line read a key this payload does not carry.
            ->assertRedirect(route('admin.students'));

        $this->assertDatabaseHas('students', ['lrn' => '100000000305', 'section_id' => $section->id, 'gender' => 'female']);
        $this->assertDatabaseMissing('students', ['lrn' => '100000000306']);
    }

    public function test_draft_roster_export_uses_the_five_column_format(): void
    {
        $admin = User::factory()->admin()->create();
        $this->withSession(['roster_extraction' => [
            'section_id' => Section::factory()->create()->id, 'source_filename' => 'ecr.xlsx',
            'rows' => [['lrn' => '100000000307', 'last_name' => 'Cruz', 'first_name' => 'Juan', 'middle_name' => 'R', 'gender' => 'male']],
            'skipped_empty' => 0, 'missing_lrn_count' => 0,
        ]])->actingAs($admin)->get(route('admin.students.extract-roster.download'))
            ->assertOk()
            ->assertSee("lrn,last_name,first_name,middle_name,gender\n", false)
            ->assertDontSee('birthdate');
    }

    /**
     * Item 5 — the migration itself: roll the drop back, put learners with
     * birthdates and academic history in place the way a pre-migration
     * database would have them, run the drop again, and prove nothing but
     * the column is gone.
     */
    public function test_existing_students_and_academic_history_survive_the_birthdate_migration(): void
    {
        $path = 'database/migrations/2026_09_18_000001_drop_birthdate_from_students_table.php';

        Artisan::call('migrate:rollback', ['--path' => $path, '--realpath' => false]);
        $this->assertTrue(Schema::hasColumn('students', 'birthdate'), 'Rollback should restore the column so the pre-migration state can be reproduced.');

        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id, 'school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['grade_level' => $section->grade_level, 'type' => 'core']);
        AcademicTerm::ensureExistFor('2026-2027');

        $studentId = DB::table('students')->insertGetId([
            'lrn' => '100000000308', 'last_name' => 'Legacy', 'first_name' => 'Learner', 'middle_name' => 'M',
            'gender' => 'male', 'birthdate' => '2008-01-01', 'section_id' => $section->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $gradeId = DB::table('grades')->insertGetId([
            'student_id' => $studentId, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'encoded_by' => $adviser->id, 'grading_period' => 1, 'grade' => 88.5, 'school_year' => '2026-2027',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $studentCountBefore = DB::table('students')->count();

        Artisan::call('migrate', ['--path' => $path, '--realpath' => false]);

        $this->assertFalse(Schema::hasColumn('students', 'birthdate'));
        $this->assertSame($studentCountBefore, DB::table('students')->count());
        $this->assertDatabaseHas('students', ['id' => $studentId, 'lrn' => '100000000308', 'last_name' => 'Legacy', 'first_name' => 'Learner', 'middle_name' => 'M', 'gender' => 'male', 'section_id' => $section->id]);
        $this->assertDatabaseHas('grades', ['id' => $gradeId, 'student_id' => $studentId, 'grade' => 88.5, 'grading_period' => 1]);
        $this->assertSame(1, Grade::where('student_id', $studentId)->count());
        $this->assertNotNull(Student::find($studentId));
    }
}
