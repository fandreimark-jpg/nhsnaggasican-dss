<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Release-readiness pass (2026-09-28). A role change must not strand a
 * current section or class (found live: a section's adviser account had
 * been changed to Subject Teacher, so nobody could open its advisory pages
 * or verify its grades), role changes are logged, and dss:check-integrity
 * reports any such record that already exists instead of fixing it.
 */
class ReleaseReadinessGuardsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $adviser;

    protected function setUp(): void
    {
        parent::setUp();
        AcademicYear::create(['school_year' => '2026-2027', 'is_active' => true]);
        AcademicTerm::ensureExistFor('2026-2027');
        $this->admin = User::factory()->admin()->create();
        $this->adviser = User::factory()->create(['role' => 'adviser', 'last_name' => 'Cruz', 'first_name' => 'Bong', 'email' => 'bong@naggasican.edu.ph']);
    }

    private function changeRole(User $user, string $role)
    {
        return $this->actingAs($this->admin)->put(route('admin.users.update', $user->id), [
            'last_name' => $user->last_name, 'first_name' => $user->first_name, 'middle_name' => null,
            'username' => strstr($user->email, '@', true), 'role' => $role,
        ]);
    }

    public function test_an_account_advising_a_current_section_keeps_the_adviser_role_until_the_section_is_reassigned(): void
    {
        $section = Section::factory()->create(['name' => 'Agila', 'school_year' => '2026-2027', 'adviser_id' => $this->adviser->id]);

        $this->changeRole($this->adviser, 'subject_teacher')->assertSessionHasErrors('role');
        $this->assertSame('adviser', $this->adviser->fresh()->role);
        $this->assertSame(0, ActivityLog::where('action', 'change_user_role')->count());

        // After the Admin moves the section, the change goes through and is logged.
        $section->update(['adviser_id' => null]);
        $this->changeRole($this->adviser, 'subject_teacher')->assertSessionHasNoErrors();
        $this->assertSame('subject_teacher', $this->adviser->fresh()->role);
        $this->assertStringContainsString('from adviser to subject_teacher', ActivityLog::where('action', 'change_user_role')->sole()->description);
    }

    public function test_a_past_years_section_never_blocks_a_role_change(): void
    {
        AcademicYear::create(['school_year' => '2025-2026', 'is_active' => false]);
        Section::factory()->create(['name' => 'Old', 'school_year' => '2025-2026', 'adviser_id' => $this->adviser->id]);
        $this->changeRole($this->adviser, 'subject_teacher')->assertSessionHasNoErrors();
    }

    public function test_a_teacher_with_current_assignments_cannot_become_admin_or_principal_but_can_switch_teaching_roles(): void
    {
        $teacher = User::factory()->create(['role' => 'subject_teacher', 'email' => 'carla@naggasican.edu.ph']);
        $section = Section::factory()->create(['school_year' => '2026-2027']);
        $subject = Subject::factory()->create(['type' => 'core', 'grade_level' => $section->grade_level, 'subject_group' => 'core_academic']);
        TeachingAssignment::create(['user_id' => $teacher->id, 'section_id' => $section->id, 'subject_id' => $subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);

        $this->changeRole($teacher, 'principal')->assertSessionHasErrors('role');
        $this->assertSame('subject_teacher', $teacher->fresh()->role);
        $this->changeRole($teacher, 'adviser')->assertSessionHasNoErrors();
        $this->assertSame('adviser', $teacher->fresh()->role);
    }

    public function test_the_integrity_check_reports_a_stranded_section_and_assignment_without_changing_them(): void
    {
        $teacher = User::factory()->create(['role' => 'subject_teacher']);
        $section = Section::factory()->create(['name' => 'Stranded', 'school_year' => '2026-2027', 'adviser_id' => $teacher->id]); // written directly, as a legacy row would be
        $subject = Subject::factory()->create(['type' => 'core', 'grade_level' => $section->grade_level, 'subject_group' => 'core_academic']);
        $principal = User::factory()->principal()->create();
        TeachingAssignment::create(['user_id' => $principal->id, 'section_id' => $section->id, 'subject_id' => $subject->id, 'school_year' => '2026-2027', 'grading_period' => 1]);

        Artisan::call('dss:check-integrity');
        $out = Artisan::output();
        $this->assertStringContainsString('Current sections whose adviser account is not an Adviser', $out);
        $this->assertStringContainsString('Stranded (2026-2027, account role: subject_teacher)', $out);
        $this->assertStringContainsString('Current teaching assignments held by an account that cannot teach', $out);
        $this->assertSame($teacher->id, $section->fresh()->adviser_id, 'reported, not reassigned');
    }
}
