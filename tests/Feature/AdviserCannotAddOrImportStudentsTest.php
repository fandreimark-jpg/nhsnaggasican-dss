<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Only the Admin creates or imports learners. An Adviser sees the section's
 * roster and can correct a learner's record, but has no control to add or
 * upload students on screen — and every create/import route refuses an
 * Adviser directly, server-side, not merely by hiding a button.
 */
class AdviserCannotAddOrImportStudentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_adviser_roster_page_offers_no_way_to_add_or_import_students(): void
    {
        $adviser = User::factory()->create(['role' => 'adviser']);
        Section::factory()->create(['adviser_id' => $adviser->id]);

        $this->actingAs($adviser)->get(route('adviser.students'))->assertOk()
            ->assertDontSee('Add Student')
            ->assertDontSee('Import Students')
            ->assertDontSee('Upload Students')
            ->assertDontSee(route('admin.students.store'), false)
            ->assertDontSee(route('admin.students.import'), false)
            ->assertDontSee('enctype="multipart/form-data"', false);
    }

    public function test_every_student_create_or_import_route_refuses_an_adviser(): void
    {
        $adviser = User::factory()->create(['role' => 'adviser']);
        $section = Section::factory()->create(['adviser_id' => $adviser->id]);
        $before = Student::count();
        $file = fn() => UploadedFile::fake()->createWithContent('learners.csv', "lrn,last_name,first_name,middle_name,gender\n123456789012,Test,Learner,,Male\n");

        $this->actingAs($adviser)->post(route('admin.students.store'), [
            'lrn' => '123456789012', 'last_name' => 'Test', 'first_name' => 'Learner', 'gender' => 'Male', 'section_id' => $section->id,
        ])->assertForbidden();
        $this->actingAs($adviser)->post(route('admin.students.import'), ['file' => $file(), 'section_id' => $section->id])->assertForbidden();
        $this->actingAs($adviser)->post(route('admin.students.extract-roster'), ['file' => $file()])->assertForbidden();
        $this->actingAs($adviser)->post(route('admin.students.import-from-ecr.preview'), ['file' => $file()])->assertForbidden();
        $this->actingAs($adviser)->post(route('admin.students.import-from-ecr.confirm'), [])->assertForbidden();

        $this->assertSame($before, Student::count(), 'no learner was created');
    }
}
