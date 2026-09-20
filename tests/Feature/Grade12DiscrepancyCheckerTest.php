<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Track;
use App\Services\Grade12DiscrepancyChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Grade12DiscrepancyChecker::compare() -- the Grade 12 workbook's OWN
 * computed Term Grade vs GradingEngine's independent result, for the SAME
 * raw scores actually imported into the database. Run AFTER a real HTTP
 * import (GradingEngine reads assessment_scores/assessments, not Excel),
 * against tests/Fixtures/GRADE-12-SANITIZED.xlsx.
 */
class Grade12DiscrepancyCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_compare_reports_a_status_for_every_matched_student_never_silently_resolving_a_mismatch(): void
    {
        $track = Track::factory()->create(['code' => 'TECHPRO']);
        $section = Section::factory()->create([
            'name' => 'AGILA', 'grade_level' => 12, 'track_id' => $track->id, 'school_year' => '2026-2027',
        ]);
        // Configured as the Arts, Social Sciences and Humanities category
        // (20/60/20) — the split the AGILA record declares. With an unset
        // curriculum in SY 2026-2027 the section grades under DO 015
        // ("SSHS ECR grading correction"), so a mis-grouped subject would
        // now be refused at detect(), not silently matched by track.
        $subject = Subject::factory()->create([
            'name' => 'Community Engagement Solidarity and Citizenship', 'type' => 'elective',
            'grade_level' => 12, 'track_id' => $track->id, 'subject_group' => 'arts_sports_wellness',
        ]);
        AcademicTerm::ensureExistFor('2026-2027');
        $adviser = \App\Models\User::factory()->create(['role' => 'adviser']);
        $section->update(['adviser_id' => $adviser->id]);

        foreach ([['ALPHA', 'JUAN'], ['BRAVO', 'PEDRO'], ['CHARLIE', 'MARK'], ['DELTA', 'JOSE'], ['ECHO', 'MARIA'], ['FOXTROT', 'ANA'], ['GOLF', 'ROSA']] as [$last, $first]) {
            Student::factory()->create(['section_id' => $section->id, 'last_name' => $last, 'first_name' => $first]);
        }

        $path = base_path('tests/Fixtures/GRADE-12-SANITIZED.xlsx');
        $file = new UploadedFile($path, 'GRADE-12-SANITIZED.xlsx', null, null, true);

        $detect = $this->actingAs($adviser)->post('/adviser/assessments/detect', [
            'subject_id'     => $subject->id,
            'grading_period' => 1,
            'file'           => $file,
        ]);
        $columns = $detect->viewData('columns');
        $mapping = array_map(fn($col) => [
            'name'      => $col['name'],
            'component' => $col['guessed_component'] ?? 'written_work',
            'exam_role' => $col['guessed_exam_role'] ?? null,
            'max_score' => (string) $col['file_max_score'],
        ], $columns);

        $this->actingAs($adviser)->post('/adviser/assessments/import', [
            'subject_id'      => $subject->id,
            'grading_period'  => 1,
            'stored_filename' => $detect->viewData('storedFilename'),
            'columns'         => $mapping,
        ])->assertRedirect();

        $results = (new Grade12DiscrepancyChecker())->compare($path, $section, $subject, 1, '2026-2027');

        // One result per matched student (7), every one carrying an actual
        // status -- 'match'/'mismatch'/'incomplete', never left uncompared.
        $this->assertCount(7, $results);
        foreach ($results as $r) {
            $this->assertContains($r['status'], ['match', 'mismatch', 'incomplete']);
            $this->assertNotNull($r['excel_term_grade'], 'Every fixture student has a Term Grade reference value in the workbook.');
        }
    }
}
