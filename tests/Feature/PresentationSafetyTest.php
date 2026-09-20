<?php

namespace Tests\Feature;

use App\Models\{AcademicTerm, Assessment, AssessmentScore, Grade, Section, SectionSubject, Specialization, Student, Subject, Track, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PresentationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_can_be_rerun_in_an_isolated_database(): void
    {
        $this->seed();
        $this->seed(\Database\Seeders\DepedSubjectCatalogSeeder::class);
        $tables = ['users', 'tracks', 'specializations', 'subjects', 'sections', 'academic_terms', 'transmutation_ranges', 'subject_group_weights', 'exam_role_shares', 'deped_subject_catalog'];
        $before = [];
        foreach ($tables as $table) $before[$table] = DB::table($table)->count();
        $this->seed();
        $this->seed(\Database\Seeders\DepedSubjectCatalogSeeder::class);
        foreach ($tables as $table) $this->assertSame($before[$table], DB::table($table)->count(), $table);
        $this->assertSame(1, User::where('role', 'principal')->where('is_active', true)->count());
        $this->assertSame(0, Student::count());
    }

    public function test_assessment_scores_and_audit_logs_survive_delete_attempts(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $score = AssessmentScore::factory()->create();
        $this->delete('/admin/students/'.$score->student_id)->assertSessionHasErrors('deletion');
        $this->assertDatabaseHas('assessment_scores', ['id' => $score->id, 'score' => 18]);
        $user = User::factory()->create();
        DB::table('activity_logs')->insert(['user_id' => $user->id, 'action' => 'login', 'description' => 'Demo audit event', 'created_at' => now(), 'updated_at' => now()]);
        $this->delete('/admin/users/'.$user->id)->assertSessionHasErrors('deletion');
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'login']);
    }

    public function test_admin_insert_contains_required_role_and_status(): void
    {
        $admin = User::factory()->admin()->create();
        $inserts = [];
        DB::listen(function ($query) use (&$inserts) {
            if (str_starts_with(strtolower($query->sql), 'insert into "users"')) $inserts[] = $query->sql;
        });
        $payload = ['last_name' => 'Demo', 'first_name' => 'Test', 'username' => 'audit-demo', 'password' => 'Test-password-42', 'role' => 'adviser'];
        $this->actingAs($admin)->post('/admin/users', $payload)->assertSessionHasNoErrors();
        $this->assertCount(1, $inserts);
        foreach (['name', 'email', 'password', 'role', 'is_active'] as $column) $this->assertStringContainsString('"'.$column.'"', $inserts[0]);
        $this->post('/admin/users', $payload)->assertSessionHasErrors('username');
        $this->post('/admin/users', array_replace($payload, ['username' => 'invalid', 'role' => 'superuser']))->assertSessionHasErrors('role');
    }

    public function test_disabled_sessions_are_revoked_for_every_role(): void
    {
        foreach (['admin', 'adviser', 'principal'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->withSession(['private_marker' => 'remove-me']);
            $token = session()->token();
            DB::table('users')->where('id', $user->id)->update(['is_active' => false, 'role_singleton_key' => null]);
            $this->get('/'.$role.'/dashboard')->assertRedirect('/login')->assertSessionHasErrors([
                'email' => 'Your account has been disabled. Contact the administrator.',
            ])->assertSessionMissing('private_marker');
            $this->assertGuest();
            $this->assertNotSame($token, session()->token());
        }
    }

    public function test_empty_previous_terms_cannot_open_later_terms(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        AcademicTerm::ensureExistFor('2026-2027');
        foreach ([2, 3] as $term) $this->post('/admin/academic-terms/'.$term.'/open')->assertSessionHas('error');
        $this->assertSame(1, AcademicTerm::where('is_open', true)->count());
        $this->assertTrue(AcademicTerm::isOpen('2026-2027', 1));
    }

    public function test_deletions_preserve_grades_and_attribution(): void
    {
        $admin = User::factory()->admin()->create();
        $grade = Grade::factory()->create();
        $before = $grade->fresh()->getAttributes();
        $this->actingAs($admin);
        foreach (['students' => $grade->student_id, 'users' => $grade->encoded_by] as $resource => $id) {
            $this->delete('/admin/'.$resource.'/'.$id)->assertSessionHasErrors('deletion');
            $this->assertDatabaseHas($resource, ['id' => $id]);
        }
        $this->assertSame($before, $grade->fresh()->getAttributes());
    }

    public function test_assessments_and_subject_assignments_block_deletion(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $section = Section::factory()->create(['adviser_id' => null]);
        $assessment = Assessment::factory()->create(['section_id' => $section->id]);
        $this->delete('/admin/sections/'.$section->id)->assertSessionHasErrors('deletion');
        $this->delete('/admin/subjects/'.$assessment->subject_id)->assertSessionHasErrors('deletion');
        $this->assertDatabaseHas('assessments', ['id' => $assessment->id]);
        $subject = Subject::factory()->create();
        $mapping = SectionSubject::offer($section, $subject, 1);
        $this->delete('/admin/subjects/'.$subject->id)->assertSessionHasErrors('deletion');
        $this->assertDatabaseHas('section_subjects', ['id' => $mapping->id]);
    }

    public function test_track_and_specialization_mappings_are_preserved(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $track = Track::factory()->create();
        $specialization = Specialization::factory()->create(['track_id' => $track->id]);
        $subject = Subject::factory()->create(['track_id' => $track->id, 'specialization_id' => $specialization->id]);
        $this->delete('/admin/tracks/'.$track->id)->assertSessionHasErrors('deletion');
        $this->delete('/admin/specializations/'.$specialization->id)->assertSessionHasErrors('deletion');
        $this->assertDatabaseHas('subjects', ['id' => $subject->id, 'specialization_id' => $specialization->id]);
    }
}
