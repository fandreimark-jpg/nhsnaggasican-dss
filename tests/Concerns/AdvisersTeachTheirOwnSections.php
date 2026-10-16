<?php

namespace Tests\Concerns;

use App\Models\Section;
use App\Models\Subject;
use App\Models\TeachingAssignment;

/**
 * OPT-IN test fixture ("Subject Teacher and Class Record" pass, 2026-09-28).
 *
 * Before teaching assignments existed, an adviser could encode every
 * subject of their advisory section, and hundreds of grading, upload and
 * monitoring tests were written on that premise. Advising a section no
 * longer grants any write (App\Services\TeachingAccess). A test class that
 * `use`s this trait states its premise explicitly: before every HTTP
 * request, each section's ADVISER is recorded as the teacher of every
 * subject of that section's grade level, for every term the subject is
 * taught — exactly what the Admin would record for a self-contained class.
 *
 * It never assigns anyone but a section's own adviser, never replaces an
 * assignment a test made itself, and is NOT used by the authorization
 * tests (RoleAccessMatrixTest, CrossSectionAccessTest, the teaching-scope
 * tests), which set up assignments by hand.
 */
trait AdvisersTeachTheirOwnSections
{
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->recordAdvisersAsTeachers();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function recordAdvisersAsTeachers(): void
    {
        $sections = Section::whereNotNull('adviser_id')->get();
        if ($sections->isEmpty()) {
            return;
        }
        $subjects = Subject::with('terms')->get();

        foreach ($sections as $section) {
            foreach ($subjects->where('grade_level', $section->grade_level) as $subject) {
                $terms = $subject->termNumbers() ?: [1, 2, 3];
                foreach ($terms as $term) {
                    TeachingAssignment::firstOrCreate(
                        ['section_id' => $section->id, 'subject_id' => $subject->id, 'grading_period' => (int) $term],
                        ['user_id' => $section->adviser_id, 'school_year' => $section->school_year]
                    );
                }
            }
        }
    }
}
