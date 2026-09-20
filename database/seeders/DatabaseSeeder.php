<?php

namespace Database\Seeders;

use App\Models\AcademicTerm;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Minimal, current-schema working seed — replaces a junior-high-era
 * version of this project (Filipino/MAPEH/TLE subjects with grade_level
 * 7/8 and no `type` value, students born in 2012) that threw under MySQL
 * strict mode the moment anyone ran `php artisan db:seed`, since
 * subjects.type is a NOT NULL enum with no default. `migrate:fresh
 * --seed` — the first command most reviewers run — failed immediately.
 *
 * Deliberately seeds NO students and NO grades — those come from the
 * Admin > Students / Adviser > Assessments import flows, and mixing fake
 * seeded learners in with real imported ones has already caused
 * confusion once. Everything here uses firstOrCreate so re-running
 * `php artisan db:seed` is always a no-op.
 */
class DatabaseSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const SCHOOL_YEAR = '2026-2027';

    public function run(): void
    {
        $admin     = $this->makeUser('admin@naggasican.edu.ph', 'Admin', 'System', 'admin');
        $adviser   = $this->makeUser('adviser1@naggasican.edu.ph', 'Dela Cruz', 'Juan', 'adviser');
        $principal = $this->makeUser('principal1@naggasican.edu.ph', 'Santos', 'Maria', 'principal');

        $this->call(TracksAndSpecializationsSeeder::class);
        $this->call(TransmutationRangesSeeder::class);
        $this->call(Do015TransmutationSeeder::class);
        $this->call(SubjectGroupWeightsSeeder::class);
        $this->call(ExamRoleSharesSeeder::class);

        // A section with a null track_id/specialization_id renders as an
        // honest "Not set" everywhere (see Task 3 of the "separate
        // intervention discovery from tracking" prompt) — but this seeded
        // demo section is a real Academic Track / STEM section throughout
        // this codebase's other fixtures and tests, so it must actually
        // carry those values, not rely on the display's null-handling to
        // paper over a seeder that forgot to set them.
        $track = Track::where('code', 'ACAD')->first();
        $specialization = Specialization::where('code', 'STEM')->where('track_id', $track?->id)->first();

        $section = Section::firstOrCreate(
            ['name' => 'Narra', 'school_year' => self::SCHOOL_YEAR],
            [
                'grade_level'       => 11,
                'adviser_id'        => $adviser->id,
                'track_id'          => $track?->id,
                'specialization_id' => $specialization?->id,
            ]
        );
        if (!$section->adviser_id || !$section->track_id || !$section->specialization_id) {
            $section->update([
                'adviser_id'        => $section->adviser_id ?: $adviser->id,
                'track_id'          => $section->track_id ?: $track?->id,
                'specialization_id' => $section->specialization_id ?: $specialization?->id,
            ]);
        }

        // Confirmed Grade 11 core subjects under the Strengthened SHS
        // curriculum — the only two established anywhere in this
        // codebase (tests/Fixtures/01_subjects_deped_shs.csv, used
        // throughout the test suite and prior demo data).
        //
        // TODO(curriculum): DepEd's Strengthened SHS core list for
        // Grade 11 has more entries than these two — not confirmed
        // anywhere in this repo, so they are deliberately NOT guessed
        // here. Add them once confirmed. See CLAUDE.md's "Known
        // limitations" section.
        foreach (['General Mathematics', 'Oral Communication'] as $name) {
            $subject = Subject::firstOrCreate(
                ['name' => $name, 'grade_level' => 11],
                ['type' => 'core']
            );
            // Terms Taught: every term, the same default the subject_terms
            // migration backfilled — narrowed by the Admin, never guessed.
            if ($subject->terms()->doesntExist()) {
                $subject->syncTerms(AcademicTerm::TERM_NUMBERS);
            }
        }

        // The demo year's AcademicYear row + Term 1/2/3. Activated ONLY when
        // no year is active yet (a fresh install) — re-seeding a live
        // database whose Admin has since activated a later year must never
        // flip the active year back ("Multi-school-year academic history"
        // work order: the active year is data, never a seeder side effect).
        AcademicTerm::ensureExistFor(self::SCHOOL_YEAR);
        if (!\App\Models\AcademicYear::active()) {
            \App\Models\AcademicYear::ensureFor(self::SCHOOL_YEAR)->forceFill(['is_active' => true])->save();
        }

        $this->command?->info('');
        $this->command?->info('Demo accounts seeded. Change default credentials before use.');
        $this->command?->table(['Role', 'Email'], [
            ['Admin', $admin->email],
            ['Adviser', $adviser->email],
            ['Principal', $principal->email],
        ]);
    }

    /**
     * 'role' isn't mass-assignable (see User::$fillable's comment) — set
     * it via direct property assignment, the same pattern UserFactory's
     * role states and Admin\UserController already use.
     */
    private function makeUser(string $email, string $lastName, string $firstName, string $role): User
    {
        $existing = User::where('email', $email)->first();
        if ($existing) return $existing;
        if (User::isSingletonRole($role) && ($existing = User::where('role', $role)->where('is_active', true)->first())) return $existing;

        $user = User::firstOrNew(
            ['email' => $email],
            [
                'name'       => $firstName . ' ' . $lastName,
                'last_name'  => $lastName,
                'first_name' => $firstName,
                'password'   => Hash::make(self::PASSWORD),
            ]
        );

        $user->role = $role;
        $user->save();

        return $user;
    }
}
