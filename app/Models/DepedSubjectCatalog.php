<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the official DepEd Strengthened SHS Electronic Class Record's
 * subject catalog (`HELPER!J7:AC161`, `ECRSHS2026`, `2026_v1.0`) — 141
 * subjects, per-subject weights. This is the top of the resolution order
 * `GradingEngine` reads: a linked catalog row (via `Subject::catalog_id`)
 * always wins over `SubjectGroupWeight`'s 6-bucket simplification — see
 * "ECR alignment" work order, PART 2.
 *
 * `ex_weight`/`st1_share`/`st2_share`/`te_share` null means the component or
 * role does not exist for this subject at all — never that it is worth zero.
 * `teacher_supplied` is true only for the two `OTHER ELECTIVE / SPECIAL
 * CURRICULAR PROGRAM` rows, where the order itself publishes no weight; every
 * other weight/share column on those two rows is null.
 */
class DepedSubjectCatalog extends Model
{
    protected $table = 'deped_subject_catalog';

    protected $fillable = [
        'scheme', 'track', 'cluster', 'course_title',
        'total_hours', 'grade_levels',
        'g11_terms', 'g11_units_per_term', 'g11_units_per_year',
        'g12_terms', 'g12_units_per_term', 'g12_units_per_year',
        'ww_weight', 'pt_weight', 'ex_weight',
        'st1_share', 'st2_share', 'te_share',
        'teacher_supplied',
    ];

    protected $casts = [
        'total_hours'         => 'integer',
        'g11_terms'            => 'integer',
        'g11_units_per_term'   => 'decimal:2',
        'g11_units_per_year'   => 'decimal:2',
        'g12_terms'            => 'integer',
        'g12_units_per_term'   => 'decimal:2',
        'g12_units_per_year'   => 'decimal:2',
        'ww_weight'            => 'decimal:2',
        'pt_weight'            => 'decimal:2',
        'ex_weight'            => 'decimal:2',
        'st1_share'            => 'decimal:2',
        'st2_share'            => 'decimal:2',
        'te_share'             => 'decimal:2',
        'teacher_supplied'     => 'boolean',
    ];

    public function subjects()
    {
        return $this->hasMany(Subject::class, 'catalog_id');
    }

    /**
     * `['st1' => x, 'st2' => y, 'term_exam' => z]` with null roles filtered
     * out, so GradingEngine::examinationPercentage()'s existing "role
     * present with no share on file -> equal split among present roles"
     * fallback keeps working unchanged for whatever this doesn't supply.
     */
    public function examRoleShares(): array
    {
        return array_filter([
            'st1'       => $this->st1_share !== null ? (float) $this->st1_share : null,
            'st2'       => $this->st2_share !== null ? (float) $this->st2_share : null,
            'term_exam' => $this->te_share !== null ? (float) $this->te_share : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Reads and normalises database/seeders/deped_sshs_catalog.csv — the
     * 141-row extraction of HELPER!J7:AC161, shared by the migration (fresh
     * environments) and DepedSubjectCatalogSeeder (re-seeding after a future
     * catalog revision), so the parsing rules live once. Header:
     * track,cluster,course_title,total_hours,grade_lvl,g11,g11_terms,
     * g11_units_per_term,g11_units_per_year,g12,g12_terms,
     * g12_units_per_term,g12_units_per_year,ww,pt,ex,st1,st2,te
     *
     * Rules, per the extraction notes:
     * - An empty ww/pt/ex/st1/st2/te cell means that component/role does not
     *   exist for this subject -> null, never 0.
     * - The literal string TEACHER in the weight columns (the two "OTHER
     *   ELECTIVE / SPECIAL CURRICULAR PROGRAM" rows) means the order
     *   publishes no weight at all -> every weight/share column null,
     *   teacher_supplied = true. The string itself is never stored.
     * - grade_lvl is inconsistent as printed ("11 ", "gr 12", "11, 12") and
     *   is NOT trusted -> grade_levels is derived from whether g11/g12 are
     *   themselves non-blank.
     * - cluster carries trailing spaces on some Tech-Pro rows in the source
     *   -> trimmed on read; the trimmed value is the match key.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rowsFromCsv(): array
    {
        $path = database_path('seeders/deped_sshs_catalog.csv');
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$path}");
        }

        $header = fgetcsv($handle);
        $rows = [];

        $blankToNull = fn ($v) => trim((string) $v) === '' ? null : trim((string) $v);
        $num = function ($v) use ($blankToNull) {
            $v = $blankToNull($v);
            return $v === null ? null : (float) $v;
        };
        $int = function ($v) use ($blankToNull) {
            $v = $blankToNull($v);
            return $v === null ? null : (int) round((float) $v);
        };

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === 1 && trim((string) $data[0]) === '') {
                continue; // trailing blank line
            }

            $row = array_combine($header, $data);

            $teacherSupplied = strtoupper(trim((string) $row['ww'])) === 'TEACHER';

            $g11Present = $blankToNull($row['g11']) !== null;
            $g12Present = $blankToNull($row['g12']) !== null;
            $gradeLevels = implode(',', array_filter([$g11Present ? '11' : null, $g12Present ? '12' : null]));

            $rows[] = [
                'scheme'             => 'do015_2026',
                'track'              => trim($row['track']),
                'cluster'            => trim($row['cluster']),
                'course_title'       => trim($row['course_title']),
                'total_hours'        => $int($row['total_hours']),
                'grade_levels'       => $gradeLevels,
                'g11_terms'          => $int($row['g11_terms']),
                'g11_units_per_term' => $num($row['g11_units_per_term']),
                'g11_units_per_year' => $num($row['g11_units_per_year']),
                'g12_terms'          => $int($row['g12_terms']),
                'g12_units_per_term' => $num($row['g12_units_per_term']),
                'g12_units_per_year' => $num($row['g12_units_per_year']),
                'ww_weight'          => $teacherSupplied ? null : $num($row['ww']),
                'pt_weight'          => $teacherSupplied ? null : $num($row['pt']),
                'ex_weight'          => $teacherSupplied ? null : $num($row['ex']),
                'st1_share'          => $teacherSupplied ? null : $num($row['st1']),
                'st2_share'          => $teacherSupplied ? null : $num($row['st2']),
                'te_share'           => $teacherSupplied ? null : $num($row['te']),
                'teacher_supplied'   => $teacherSupplied,
            ];
        }

        fclose($handle);

        return $rows;
    }
}
