<?php

namespace Tests\Feature;

use App\Models\FaceEnrollment;
use App\Models\User;
use App\Models\Workplace;
use App\Services\Face\FaceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AttendanceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_clock_in_is_server_timed_and_idempotent_for_both_clients(): void
    {
        $user = User::factory()->create();
        $workplace = Workplace::query()->create([
            'name' => 'Studio',
            'latitude' => 37.7749,
            'longitude' => -122.4194,
            'radius_meters' => 250,
        ]);
        $user->workplaceAssignments()->create(['workplace_id' => $workplace->id]);
        FaceEnrollment::query()->create([
            'user_id' => $user->id,
            'provider' => 'test',
            'provider_reference' => 'face-1',
            'enrolled_at' => now(),
        ]);
        $this->app->instance(FaceProvider::class, new class implements FaceProvider
        {
            public function name(): string
            {
                return 'test';
            }

            public function enroll(User $user, UploadedFile $image): string
            {
                return 'face-1';
            }

            public function verify(User $user, UploadedFile $image): bool
            {
                return true;
            }
        });

        $payload = [
            'workplace_id' => $workplace->id,
            'latitude' => 37.7749,
            'longitude' => -122.4194,
            'face_image' => UploadedFile::fake()->create('face.jpg', 10, 'image/jpeg'),
        ];
        $this->actingAs($user)->post('/api/v1/attendance/clock-in', $payload, [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'clock-in-request-01',
        ])->assertCreated()->assertJsonPath('data.attendance.status', 'clocked_in');

        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'workplace_id' => $workplace->id,
            'status' => 'clocked_in',
        ]);

        $this->actingAs($user)->post('/api/v1/attendance/clock-in', $payload, [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'clock-in-request-01',
        ])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $differentPayload = [...$payload, 'latitude' => 37.7750];
        $this->actingAs($user)->post('/api/v1/attendance/clock-in', $differentPayload, [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'clock-in-request-01',
        ])->assertConflict()->assertJsonPath('error.code', 'idempotency_key_reused');

        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_location_outside_the_assigned_workplace_is_rejected(): void
    {
        $user = User::factory()->create();
        $workplace = Workplace::query()->create([
            'name' => 'Office',
            'latitude' => 37.7749,
            'longitude' => -122.4194,
            'radius_meters' => 100,
        ]);
        $user->workplaceAssignments()->create(['workplace_id' => $workplace->id]);
        FaceEnrollment::query()->create([
            'user_id' => $user->id,
            'provider' => 'test',
            'provider_reference' => 'face-2',
            'enrolled_at' => now(),
        ]);
        $this->app->instance(FaceProvider::class, new class implements FaceProvider
        {
            public function name(): string
            {
                return 'test';
            }

            public function enroll(User $user, UploadedFile $image): string
            {
                return 'face-2';
            }

            public function verify(User $user, UploadedFile $image): bool
            {
                return true;
            }
        });

        $this->actingAs($user)->post('/api/v1/attendance/clock-in', [
            'workplace_id' => $workplace->id,
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'face_image' => UploadedFile::fake()->create('face.jpg', 10, 'image/jpeg'),
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'clock-in-outside-01',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'outside_workplace_radius');

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_active_attendance_can_be_completed_after_midnight(): void
    {
        $this->travelTo(now()->setTime(23, 55));
        $user = User::factory()->create();
        $workplace = Workplace::query()->create([
            'name' => 'Night Shift',
            'latitude' => 51.5072,
            'longitude' => -0.1276,
            'radius_meters' => 200,
        ]);
        $user->workplaceAssignments()->create(['workplace_id' => $workplace->id]);
        FaceEnrollment::query()->create([
            'user_id' => $user->id,
            'provider' => 'test',
            'provider_reference' => 'night-face',
            'enrolled_at' => now(),
        ]);
        $this->app->instance(FaceProvider::class, new class implements FaceProvider
        {
            public function name(): string
            {
                return 'test';
            }

            public function enroll(User $user, UploadedFile $image): string
            {
                return 'night-face';
            }

            public function verify(User $user, UploadedFile $image): bool
            {
                return true;
            }
        });

        $this->actingAs($user)->post('/api/v1/attendance/clock-in', [
            'workplace_id' => $workplace->id,
            'latitude' => 51.5072,
            'longitude' => -0.1276,
            'face_image' => UploadedFile::fake()->create('face.jpg', 10, 'image/jpeg'),
        ], ['Accept' => 'application/json', 'Idempotency-Key' => 'night-clock-in-01'])->assertCreated();

        $this->travelTo(now()->addDay()->setTime(0, 5));
        $this->actingAs($user)->getJson('/api/v1/attendance/status')
            ->assertOk()
            ->assertJsonPath('data.attendance.status', 'clocked_in');
        $this->actingAs($user)->post('/api/v1/attendance/clock-out', [
            'latitude' => 51.5072,
            'longitude' => -0.1276,
            'face_image' => UploadedFile::fake()->create('face.jpg', 10, 'image/jpeg'),
        ], ['Accept' => 'application/json', 'Idempotency-Key' => 'night-clock-out-01'])->assertOk();

        $this->assertDatabaseHas('attendance_records', ['user_id' => $user->id, 'status' => 'completed']);
    }
}
