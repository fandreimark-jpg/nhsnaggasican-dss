<?php

namespace Tests\Concerns;

use App\Models\Assessment;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;

/**
 * Test fixtures that record "a learner's score in a component" the way a
 * real class record holds it: ONE assessment column per class, shared by
 * every learner. Since the workbook-parity change (D1, 2026-10-04) every
 * assessment in a class/term applies to every learner (its HPS is in each
 * learner's denominator), so a fixture that gave each learner a private
 * item in a shared class would silently charge every other learner a blank.
 *
 * Reuses an item of that component and HPS the learner has no score on yet;
 * creates one when there is none (a second score for the same learner goes
 * into a second column, as it would on paper).
 */
trait SharesClassAssessments
{
    protected function sharedAssessment(Section $section, Subject $subject, Student $student, string $component, float $max, int $term = 1): Assessment
    {
        $existing = Assessment::where('section_id', $section->id)->where('subject_id', $subject->id)
            ->where('grading_period', $term)->where('school_year', $section->school_year)
            ->where('component', $component)->where('max_score', $max)
            ->whereDoesntHave('scores', fn($q) => $q->where('student_id', $student->id))
            ->orderBy('id')->first();

        return $existing ?? Assessment::factory()->create([
            'subject_id' => $subject->id, 'section_id' => $section->id,
            'grading_period' => $term, 'school_year' => $section->school_year,
            'name' => $component . '-' . uniqid(), 'component' => $component, 'max_score' => $max,
        ]);
    }
}
