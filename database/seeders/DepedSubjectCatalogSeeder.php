<?php

namespace Database\Seeders;

use App\Models\DepedSubjectCatalog;
use Illuminate\Database\Seeder;

/**
 * "ECR alignment" work order, PART 2a -- re-seeds the DepEd Strengthened SHS
 * catalog from database/seeders/deped_sshs_catalog.csv, idempotently by
 * (scheme, course_title, track). The 2026_09_10_000001 migration seeds a
 * fresh environment directly; this seeder is the path in for when DepEd
 * ships a future version (e.g. 2027_v1.0) and that CSV is re-extracted and
 * replaced -- migration-only would leave a future catalog revision with no
 * way to load short of a brand new migration every time. Both read the same
 * CSV through the same DepedSubjectCatalog::rowsFromCsv() parser, so the
 * normalisation rules (empty cell = null, TEACHER handling, grade_levels
 * derived from g11/g12) live in exactly one place.
 */
class DepedSubjectCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (DepedSubjectCatalog::rowsFromCsv() as $row) {
            DepedSubjectCatalog::updateOrCreate(
                ['scheme' => $row['scheme'], 'course_title' => $row['course_title'], 'track' => $row['track']],
                $row
            );
        }

        $this->command?->info(DepedSubjectCatalog::count() . ' deped_subject_catalog row(s) on file.');
    }
}
