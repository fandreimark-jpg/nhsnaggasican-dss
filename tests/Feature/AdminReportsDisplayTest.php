<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers two display bugs on /admin/reports:
 *   - $section->adviser->last_name / ->first_name don't reflect the
 *     adviser's actual name for records where those (nullable) columns
 *     were never populated — ->name is the column the app guarantees is
 *     always set, so that's what the page must render.
 *   - "N students" was rendered unconditionally, reading "1 students" for
 *     a one-student section.
 */
class AdminReportsDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_adviser_name_renders_instead_of_a_bare_comma(): void
    {
        $admin = User::factory()->admin()->create();

        $adviser = new User();
        $adviser->name = 'Dela Cruz, Juan';
        $adviser->email = 'juan.delacruz@naggasican.edu.ph';
        $adviser->password = bcrypt('password');
        $adviser->role = 'adviser';
        $adviser->is_active = true;
        $adviser->save();

        $section = Section::factory()->create(['adviser_id' => $adviser->id]);

        $response = $this->actingAs($admin)->get('/admin/reports');

        $response->assertOk();
        $response->assertSee('Dela Cruz, Juan');
        $response->assertDontSee('Adviser: ,', false);
    }

    public function test_one_student_section_reads_1_student_not_1_students(): void
    {
        $admin   = User::factory()->admin()->create();
        $section = Section::factory()->create();
        Student::factory()->create(['section_id' => $section->id]);

        $response = $this->actingAs($admin)->get('/admin/reports');

        $response->assertOk();
        $response->assertSee('1 student', false);
        $response->assertDontSee('1 students', false);
    }
}
