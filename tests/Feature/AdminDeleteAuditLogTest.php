<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Section;
use App\Models\Specialization;
use App\Models\Subject;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pre-demo audit (2026-10-06): Admin deletes of master data left no
 * activity-log entry (a working-data subject vanished without a trail). Every
 * successful delete is now logged; a refused delete logs nothing.
 */
class AdminDeleteAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_master_data_delete_is_logged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $track = Track::create(['code' => 'TVL', 'name' => 'TVL Track']);
        $spec = Specialization::create(['code' => 'ICT', 'name' => 'ICT', 'track_id' => $track->id]);
        $subject = Subject::factory()->create(['name' => 'Old Subject']);
        $section = Section::factory()->create(['name' => 'Old Section', 'adviser_id' => null]);
        $other = User::factory()->create(['role' => 'adviser', 'name' => 'Spare, Account']);

        foreach ([['admin.subjects.destroy', $subject->id, 'delete_subject'], ['admin.sections.destroy', $section->id, 'delete_section'],
                  ['admin.specializations.destroy', $spec->id, 'delete_specialization'], ['admin.tracks.destroy', $track->id, 'delete_track'],
                  ['admin.users.destroy', $other->id, 'delete_user']] as [$route, $id, $action]) {
            $this->actingAs($admin)->delete(route($route, $id))->assertSessionHas('success');
            $log = ActivityLog::where('action', $action)->sole();
            $this->assertSame([$admin->id, $id], [$log->user_id, $log->record_id], $action);
        }
        $this->assertStringContainsString('Old Subject', ActivityLog::where('action', 'delete_subject')->value('description'));
    }

    public function test_a_refused_delete_is_not_logged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->delete(route('admin.users.destroy', $admin->id))->assertSessionHas('error');
        $this->assertSame(0, ActivityLog::where('action', 'delete_user')->count());
    }
}
