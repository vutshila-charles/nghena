<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\CorrectionRequest;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_login_accounts_and_sample_attendance_idempotently(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@clockin.test',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'jordan@clockin.test',
            'role' => 'employee',
            'is_active' => true,
        ]);

        $admin = User::query()->where('email', 'admin@clockin.test')->firstOrFail();
        $this->assertTrue(Hash::check(config('demo.password'), $admin->password));
        $this->assertSame(2, Workplace::query()->count());
        $this->assertSame(2, Shift::query()->count());
        $this->assertGreaterThan(0, AttendanceRecord::query()->count());
        $this->assertSame('pending', CorrectionRequest::query()->firstOrFail()->status);
        $this->assertTrue($admin->workplaceAssignments()->exists());

        $counts = [
            User::query()->count(),
            Workplace::query()->count(),
            Shift::query()->count(),
            AttendanceRecord::query()->count(),
            CorrectionRequest::query()->count(),
        ];

        $this->seed();

        $this->assertSame($counts, [
            User::query()->count(),
            Workplace::query()->count(),
            Shift::query()->count(),
            AttendanceRecord::query()->count(),
            CorrectionRequest::query()->count(),
        ]);
    }
}
