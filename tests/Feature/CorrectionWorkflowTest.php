<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\CorrectionRequest;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CorrectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_approval_creates_the_requested_missing_attendance_record(): void
    {
        $employee = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $workplace = Workplace::query()->create([
            'name' => 'Main Office',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'radius_meters' => 150,
        ]);
        $employee->workplaceAssignments()->create(['workplace_id' => $workplace->id]);
        $workDate = now()->subDay()->toDateString();

        Sanctum::actingAs($employee);
        $correctionId = $this->postJson('/api/v1/attendance/corrections', [
            'workplace_id' => $workplace->id,
            'work_date' => $workDate,
            'clocked_in_at' => $workDate.'T08:30:00Z',
            'clocked_out_at' => $workDate.'T17:00:00Z',
            'reason' => 'Forgot to clock in.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        $this->assertDatabaseHas('correction_requests', ['id' => $correctionId, 'user_id' => $employee->id]);
        $this->assertDatabaseCount('attendance_records', 0);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/admin/corrections/{$correctionId}", [
            'decision' => 'approved',
            'review_note' => 'Approved after review.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $record = AttendanceRecord::query()->sole();
        $this->assertSame($employee->id, $record->user_id);
        $this->assertSame($workDate, $record->work_date->toDateString());
        $this->assertSame('completed', $record->status);

        $this->patchJson("/api/v1/admin/corrections/{$correctionId}", [
            'decision' => 'rejected',
        ])->assertConflict()->assertJsonPath('error.code', 'correction_already_reviewed');
        $this->assertSame('approved', CorrectionRequest::query()->findOrFail($correctionId)->status);
    }
}
