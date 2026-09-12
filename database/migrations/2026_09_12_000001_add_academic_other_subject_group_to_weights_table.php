<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Subject classification and grading weights cleanup" pass.
 *
 * `core_academic` (20/50/30) was the only do015_2026 group carrying that
 * split, so it was doing double duty: DepEd's own catalog gives the exact
 * same 20/50/30 to two DIFFERENT things — Profile A (CORE subjects
 * themselves) and Profile B ("Academic Elective — all other," i.e. STEM
 * and Business & Entrepreneurship cluster electives, per the catalog table
 * in CLAUDE.md). Sharing one slug between them is the direct cause of the
 * "TYPE: Elective, SUBJECT GROUP: Core Academic" contradiction — an
 * elective correctly weighted at 20/50/30 had no honest group to sit in
 * except the one literally named for Core subjects.
 *
 * `academic_other` is the same 20/50/30 split, seeded as its own distinct
 * row so an elective can be correctly weighted without being labelled
 * Core. `core_academic` itself is now reserved for type=core subjects only
 * — enforced in SubjectGroupWeight::classificationError(), called from
 * both Admin\SubjectController and SubjectsImport.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('subject_group_weights')->insert([
            'scheme' => 'do015_2026', 'subject_group' => 'academic_other',
            'ww_weight' => 20.00, 'pt_weight' => 50.00, 'ex_weight' => 30.00,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('subject_group_weights')
            ->where('scheme', 'do015_2026')
            ->where('subject_group', 'academic_other')
            ->delete();
    }
};
