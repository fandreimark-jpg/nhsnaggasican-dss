<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Intervention;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Final pre-demo audit (2026-09-20). Every role page that renders a
 * user-typed name or note gets a markup + attribute-breakout payload.
 *
 * Found one real hole: admin/sections.blade.php built
 *   onclick='openEditSectionModal(@json($section->load(["track", "specialization"])))'
 * Blade's @json splits its argument on commas, so that compiled to
 * json_encode(..., 512) with NO JSON_HEX_APOS / JSON_HEX_TAG escaping, and
 * a section name containing a quote broke out of the attribute. Fixed by
 * passing the eager-loaded model as a single, comma-free @json argument.
 * Keep every @json(...) argument comma-free (or use Js::from()) — the
 * second test here enforces that statically.
 */
class XssEscapingAcrossRolePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_names_and_notes_with_markup_are_escaped_on_every_role_page(): void
    {
        $payload = '<script>alert("x")</script><img src=x onerror=alert(1)>' . "' onmouseover='alert(2)";

        $admin     = User::factory()->admin()->create(['name' => 'Admin ' . $payload]);
        $adviser   = User::factory()->create(['name' => 'Adv ' . $payload]);
        $principal = User::factory()->principal()->create();
        $section   = Section::factory()->create(['name' => 'Sec ' . $payload, 'adviser_id' => $adviser->id, 'school_year' => '2026-2027', 'grade_level' => 11]);
        $subject   = Subject::factory()->create(['name' => 'Subj ' . $payload, 'grade_level' => 11, 'type' => 'core', 'subject_group' => 'core_academic']);
        $student   = Student::factory()->create(['section_id' => $section->id, 'last_name' => 'Last ' . $payload, 'first_name' => 'First']);
        AcademicTerm::ensureExistFor('2026-2027');
        Intervention::factory()->create([
            'student_id' => $student->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'school_year' => '2026-2027', 'grading_period' => 1, 'created_by' => $principal->id,
            'principal_notes' => 'Note ' . $payload, 'recommendation_reason' => 'Reason ' . $payload,
        ]);

        $pages = [
            [$admin, '/admin/dashboard'], [$admin, '/admin/users'], [$admin, '/admin/students'], [$admin, '/admin/subjects'],
            [$admin, '/admin/sections'], [$admin, '/admin/activity-logs'], [$admin, "/admin/sections/{$section->id}/subjects"],
            [$adviser, '/adviser/dashboard'], [$adviser, '/adviser/students'],
            [$adviser, '/adviser/assessments?period=1&subject_id=' . $subject->id],
            [$adviser, '/adviser/grades?period=1&subject_id=' . $subject->id],
            [$adviser, '/adviser/interventions'], [$adviser, '/adviser/submit-report'],
            [$principal, '/principal/dashboard'], [$principal, '/principal/students'], [$principal, "/principal/students/{$student->id}"],
            [$principal, '/principal/interventions'],
            [$principal, '/principal/subject-analysis?grading_period=1&section_id=' . $section->id . '&subject_id=' . $subject->id],
            [$principal, '/principal/reports'],
        ];

        foreach ($pages as [$user, $url]) {
            $response = $this->actingAs($user)->get($url);
            $this->assertSame(200, $response->getStatusCode(), "$url status");
            $html = $response->getContent();
            $this->assertStringNotContainsString('<script>alert', $html, "$url renders a raw <script>");
            $this->assertStringNotContainsString('<img src=x onerror', $html, "$url renders a raw <img onerror>");
            $this->assertStringNotContainsString("' onmouseover='alert(2)", $html, "$url lets a quote break out of an attribute");
        }
    }

    /** No @json directive may carry a comma inside its argument (see class docblock). */
    public function test_no_blade_json_directive_has_a_comma_inside_its_argument(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            // Balanced-parentheses match, so two adjacent @json(...) calls
            // on one line are two arguments, not one.
            preg_match_all('/@json(\((?:[^()]++|(?1))*\))/s', $file->getContents(), $matches);
            foreach ($matches[1] as $argument) {
                $argument = substr($argument, 1, -1);
                if (str_contains($argument, ',')) {
                    $offenders[] = $file->getRelativePathname() . ': @json(' . $argument . ')';
                }
            }
        }

        $this->assertSame([], $offenders, "Blade @json splits on commas and drops its HEX escaping flags:\n" . implode("\n", $offenders));
    }
}
