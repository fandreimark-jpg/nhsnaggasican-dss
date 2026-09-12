<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Specialization;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A real bug: admin/students.blade.php passed $students->count() (the
 * paginator's CURRENT-PAGE count) to <x-count-label total>, which reads
 * "N total <noun>" -- on a school roster of 46 students at 10 per page,
 * the last page showed "6 total students" while the pagination footer on
 * the same screen correctly said "Showing 41-46 of 46". Fixed by reading
 * $students->total() instead, which is what the footer already used.
 *
 * The same-looking pattern ($var->count() with the `total` flag) also
 * appears on subjects/tracks/specializations/sections index screens, but
 * none of those four are paginated at all -- their controllers use ->get(),
 * not ->paginate(), so $var is a plain Collection and count() IS the true
 * total. Applying the same "fix" there would call ->total() on a
 * Collection, which doesn't exist, and break the page. These four tests
 * pin the current, correct behavior so a future switch to pagination on
 * any of them doesn't silently reintroduce the students.blade.php bug.
 */
class AdminIndexTotalCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_page_shows_the_true_total_not_the_current_page_count(): void
    {
        $admin = User::factory()->admin()->create();
        $section = Section::factory()->create();
        Student::factory()->count(25)->create(['section_id' => $section->id]);

        // Page size is 10 (StudentController::index()). Page 3 of 25 holds
        // only 5 rows -- the only state where the bug was visible, since
        // page 1's current-page count (10) and the real total (25) don't
        // collide by coincidence the way a single-page screen's would.
        $response = $this->actingAs($admin)->get('/admin/students?page=3');

        $response->assertOk();
        $response->assertSee('25 total students');
        // ">5 total students<", not the bare substring "5 total students" --
        // "25 total students" itself contains "5 total students" as a
        // substring, so a plain assertDontSee() here would false-positive
        // against the correct output.
        $response->assertDontSee('>5 total students<', false);
    }

    public function test_subjects_page_is_not_paginated_and_shows_the_full_count(): void
    {
        $admin = User::factory()->admin()->create();
        // Subject::create() directly, bypassing SubjectFactory -- its
        // default 'name' draws from fake()->unique()->randomElement() over
        // a fixed 6-item list, which overflows past 6 rows regardless of
        // any override merged on top; unrelated to the bug under test.
        for ($i = 1; $i <= 15; $i++) {
            Subject::create(['name' => "Test Subject {$i}", 'type' => 'core', 'grade_level' => 11]);
        }

        // No ?page= query at all -- there is no pagination on this screen,
        // so there is no "other page" to check from. The invariant this
        // pins is that count() (a Collection here, not a paginator) already
        // equals the true total; that stops being true the moment someone
        // adds ->paginate() to SubjectController::index() without also
        // switching the blade to ->total().
        $response = $this->actingAs($admin)->get('/admin/subjects');

        $response->assertOk();
        $response->assertSee('15 total subjects');
    }

    public function test_tracks_page_is_not_paginated_and_shows_the_full_count(): void
    {
        $admin = User::factory()->admin()->create();
        Track::factory()->count(12)->sequence(
            fn ($seq) => ['name' => 'Track ' . $seq->index, 'code' => 'T' . $seq->index]
        )->create();

        $response = $this->actingAs($admin)->get('/admin/tracks');

        $response->assertOk();
        $response->assertSee('12 total tracks');
    }

    public function test_specializations_page_is_not_paginated_and_shows_the_full_count(): void
    {
        $admin = User::factory()->admin()->create();
        $track = Track::factory()->create();
        Specialization::factory()->count(15)->sequence(
            fn ($seq) => ['track_id' => $track->id, 'name' => 'Spec ' . $seq->index, 'code' => 'SP' . $seq->index]
        )->create();

        $response = $this->actingAs($admin)->get('/admin/specializations');

        $response->assertOk();
        $response->assertSee('15 total specializations');
    }

    public function test_sections_page_is_not_paginated_and_shows_the_full_count(): void
    {
        $admin = User::factory()->admin()->create();
        Section::factory()->count(13)->create();

        $response = $this->actingAs($admin)->get('/admin/sections');

        $response->assertOk();
        $response->assertSee('13 total sections');
    }
}
