<?php

namespace Database\Seeders;

use App\Models\ExamRoleShare;
use Illuminate\Database\Seeder;

/**
 * How the Examination component splits between the two Summative Tests
 * and the Term Examination under DO 015, s. 2026 — see GradingEngine's
 * examinationPercentage().
 *
 * TODO(curriculum): these three numbers (30/30/40) are PROVISIONAL — not
 * confirmed anywhere in this codebase against the published order itself.
 * Correcting them once confirmed is three UPDATEs to this table, never a
 * deployment — that's the point of keeping this as data. See CLAUDE.md's
 * "Known limitations".
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
