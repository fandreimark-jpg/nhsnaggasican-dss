<?php

namespace Database\Seeders;

use App\Models\ExamRoleShare;
use Illuminate\Database\Seeder;

/**
 * How the Examination component splits between the two Summative Tests
 * and the Term Examination under DO 015, s. 2026 — see GradingEngine's
 * examinationPercentage().
 *
 * STATUS OF THESE THREE NUMBERS (re-checked in the 2026-09-17 pre-demo
 * audit — do not read the older "PROVISIONAL" wording in git history as
 * still current):
 *
 * - They are the scheme-wide FALLBACK only. GradingEngine::
 *   examinationPercentage() reads a subject's linked deped_subject_catalog
 *   row first (its st1_share / st2_share / te_share came straight from
 *   HELPER!J7:AC161 of DepEd's own SSHS E-Class Record, ECRSHS2026
 *   2026_v1.0), and reaches this table only for a do015_2026 subject with
 *   no catalog link or a catalog row whose shares are all null.
 * - 30/30/40 matches the value the catalog carries for the large majority
 *   of its rows (122 of 141 catalog rows carry a non-null st1_share), but
 *   it is NOT universal: eight subjects are TE-only and nine have no
 *   Examination component at all — see CLAUDE.md, "The Examination role
 *   split is per subject, not universal". A subject that lands on this
 *   fallback is therefore computing on the COMMON case, not on its own
 *   confirmed row, and the honest fix is to link it to its catalog row
 *   (subjects.catalog_id), not to edit these numbers.
 * - Neither the catalog nor this table has been checked against the
 *   signed PDF of DO 015, s. 2026 itself. That remains a documented
 *   release limitation; correcting a share is three UPDATEs to this
 *   table, never a deployment.
 */
class ExamRoleSharesSeeder extends Seeder
{
    private const SCHEME = 'do015_2026';

    /** @var array<int, array{0: string, 1: float}> [exam_role, share] */
    private const SHARES = [
        ['st1', 30.00],
        ['st2', 30.00],
        ['term_exam', 40.00],
    ];

    public function run(): void
    {
        foreach (self::SHARES as [$role, $share]) {
            ExamRoleShare::firstOrCreate(
                ['scheme' => self::SCHEME, 'exam_role' => $role],
                ['share' => $share]
            );
        }
    }
}
