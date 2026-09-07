<?php

namespace Database\Seeders;

use App\Models\Specialization;
use App\Models\Track;
use Illuminate\Database\Seeder;

/**
 * Seeds the baseline DepEd SHS tracks and specializations. Idempotent —
 * uses firstOrCreate() throughout, matching App\Imports\TracksImport's own
 * semantics, so re-running this seeder never creates duplicates.
 */
class TracksAndSpecializationsSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'ACAD' => [
                'name'            => 'Academic Track',
                'specializations' => [
                    'STEM'  => 'Science, Technology, Engineering and Mathematics',
                    'HUMSS' => 'Humanities and Social Sciences',
                    'ABM'   => 'Accountancy, Business and Management',
                    'GAS'   => 'General Academic Strand',
                ],
            ],
            'TVL' => [
                'name'            => 'Technical-Vocational-Livelihood Track',
                'specializations' => [
                    'ICT' => 'Information and Communications Technology',
                    'HE'  => 'Home Economics',
                    'IA'  => 'Industrial Arts',
                    'AFA' => 'Agri-Fishery Arts',
                ],
            ],
            'ARTS' => [
                'name'            => 'Arts and Design Track',
                'specializations' => [
                    'DANCE'   => 'Dance',
                    'MEDIA'   => 'Media Arts',
                    'THEATER' => 'Theater Arts',
                    'VISUAL'  => 'Visual Arts',
                ],
            ],
        ];

        foreach ($data as $trackCode => $trackInfo) {
            $track = Track::firstOrCreate(
                ['code' => $trackCode],
                ['name' => $trackInfo['name']]
            );

            foreach ($trackInfo['specializations'] as $specCode => $specName) {
                Specialization::firstOrCreate(
                    ['track_id' => $track->id, 'code' => $specCode],
                    ['name' => $specName]
                );
            }
        }
    }
}
