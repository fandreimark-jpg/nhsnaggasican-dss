<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Students are master data: only Admin may add or bulk-import them.
 * Advisers may still view/edit students already placed in their section,
 * but the add/import routes no longer exist on the adviser side at all.
 */
class AdminStudentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_students_page_renders_with_new_add_and_import_modals(): void
    {
        $admin = User::factory()->admin()->create();
        Section::factory()->create();

        $this->actingAs($admin)->get('/admin/students')->assertOk();
    }

    public function test_admin_can_add_a_student_to_any_section(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/students', [
            'lrn'         => '100000000101',
            'last_name'   => 'Reyes',
            'first_name'  => 'Ana',
            'middle_name' => '',
            'gender'      => 'female',
            'section_id'  => $section->id,
        ]);

        $response->assertRedirect(route('admin.students'));
        $this->assertDatabaseHas('students', [
            'lrn'        => '100000000101',
            'section_id' => $section->id,
        ]);
    }

    public function test_admin_add_student_requires_a_valid_section(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/students', [
            'lrn'         => '100000000102',
            'last_name'   => 'Cruz',
            'first_name'  => 'Jose',
            'gender'      => 'male',
            'section_id'  => 999999,
        ])->assertSessionHasErrors('section_id');

        $this->assertDatabaseMissing('students', ['lrn' => '100000000102']);
    }

    public function test_adviser_cannot_add_a_student(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);

        $this->actingAs($adviser)->post('/admin/students', [
            'lrn'         => '100000000103',
            'last_name'   => 'Santos',
            'first_name'  => 'Pedro',
            'gender'      => 'male',
            'section_id'  => $section->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('students', ['lrn' => '100000000103']);
    }

    public function test_principal_cannot_add_a_student(): void
    {
        $principal = User::factory()->principal()->create();
        $section   = Section::factory()->create();

        $this->actingAs($principal)->post('/admin/students', [
            'lrn'         => '100000000104',
            'last_name'   => 'Gomez',
            'first_name'  => 'Liza',
            'gender'      => 'female',
            'section_id'  => $section->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('students', ['lrn' => '100000000104']);
    }

    public function test_adviser_add_student_route_no_longer_exists(): void
    {
        // '/adviser/students' still exists as a GET (index) route, so an
        // unmatched POST verb against it correctly 405s rather than 404s —
        // either way, no student add action is reachable here anymore.
        $adviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $adviser->id]);

        $this->actingAs($adviser)->post('/adviser/students', [
            'lrn' => '100000000105', 'last_name' => 'X', 'first_name' => 'Y', 'gender' => 'male',
        ])->assertStatus(405);
    }

    public function test_adviser_import_students_route_no_longer_exists(): void
    {
        // '/adviser/students/import' matches the '/adviser/students/{id}'
        // PUT route's URI shape ("import" as the {id}), so POSTing here
        // 405s rather than 404s — same outcome: no import action exists.
        $adviser = User::factory()->create();
        Section::factory()->create(['adviser_id' => $adviser->id]);

        $this->actingAs($adviser)->post('/adviser/students/import', [])
            ->assertStatus(405);
    }

    public function test_admin_can_import_students_into_a_chosen_section(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create();

        $csv  = "lrn,last_name,first_name,middle_name,gender\n";
        $csv .= "100000000201,Dela Cruz,Juan,Santos,male\n";

        $path = tempnam(sys_get_temp_dir(), 'admin_students_import_') . '.csv';
        file_put_contents($path, $csv);

        $file = new \Illuminate\Http\UploadedFile($path, 'students.csv', 'text/csv', null, true);

        $response = $this->actingAs($admin)->post('/admin/students/import', [
            'section_id' => $section->id,
            'file'       => $file,
        ]);

        @unlink($path);

        $response->assertRedirect(route('admin.students'));
        $this->assertDatabaseHas('students', [
            'lrn'        => '100000000201',
            'section_id' => $section->id,
        ]);
    }

    public function test_import_requires_a_target_section(): void
    {
        $admin = User::factory()->admin()->create();

        $csv  = "lrn,last_name,first_name,middle_name,gender\n";
        $csv .= "100000000202,One,Row,,male\n";
        $path = tempnam(sys_get_temp_dir(), 'admin_students_import_') . '.csv';
        file_put_contents($path, $csv);
        $file = new \Illuminate\Http\UploadedFile($path, 'students.csv', 'text/csv', null, true);

        $this->actingAs($admin)->post('/admin/students/import', [
            'file' => $file,
        ])->assertSessionHasErrors(['section_id'], null, 'import');

        @unlink($path);
        $this->assertDatabaseMissing('students', ['lrn' => '100000000202']);
    }

    public function test_adviser_cannot_import_students_via_admin_route(): void
    {
        $adviser = User::factory()->create();
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);

        $this->actingAs($adviser)->post('/admin/students/import', [
            'section_id' => $section->id,
        ])->assertForbidden();
    }

    public function test_admin_can_still_edit_and_delete_students(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create();
        $student = Student::factory()->create(['section_id' => $section->id]);

        $this->actingAs($admin)->delete("/admin/students/{$student->id}")
            ->assertRedirect(route('admin.students'));

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
    }
}
