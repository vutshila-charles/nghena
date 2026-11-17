<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\CorrectionRequest;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo data can only be seeded in local or testing environments.');
        }

        $password = config('demo.password');

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@clockin.test'],
            [
                'name' => 'Alex Morgan',
                'password' => Hash::make($password),
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $employees = collect([
            ['name' => 'Jordan Lee', 'email' => 'jordan@clockin.test'],
            ['name' => 'Sam Rivera', 'email' => 'sam@clockin.test'],
            ['name' => 'Taylor Kim', 'email' => 'taylor@clockin.test'],
            ['name' => 'Casey Patel', 'email' => 'casey@clockin.test'],
        ])->mapWithKeys(fn (array $employee) => [
            $employee['email'] => User::query()->updateOrCreate(
                ['email' => $employee['email']],
                [
                    'name' => $employee['name'],
                    'password' => Hash::make($password),
                    'role' => 'employee',
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            ),
        ]);

        $headquarters = Workplace::query()->updateOrCreate(
            ['name' => 'Clockin Headquarters'],
            [
                'address' => 'San Francisco, CA',
                'latitude' => 37.7749,
                'longitude' => -122.4194,
                'radius_meters' => 300,
                'is_active' => true,
            ],
        );
        $studio = Workplace::query()->updateOrCreate(
            ['name' => 'Design Studio'],
            [
                'address' => 'Oakland, CA',
                'latitude' => 37.8044,
                'longitude' => -122.2712,
                'radius_meters' => 250,
                'is_active' => true,
            ],
        );

        $dayShift = Shift::query()->updateOrCreate(
            ['name' => 'Day Shift'],
            ['starts_at' => '09:00', 'ends_at' => '17:00', 'break_minutes' => 30, 'is_active' => true],
        );
        $earlyShift = Shift::query()->updateOrCreate(
            ['name' => 'Early Shift'],
            ['starts_at' => '07:00', 'ends_at' => '15:00', 'break_minutes' => 30, 'is_active' => true],
        );

        $admin->workplaceAssignments()->updateOrCreate(
            ['workplace_id' => $headquarters->id],
            ['shift_id' => $dayShift->id],
        );
        foreach ($employees->values() as $index => $employee) {
            $workplace = $index === 2 ? $studio : $headquarters;
            $shift = $index === 1 ? $earlyShift : $dayShift;

            $employee->workplaceAssignments()->updateOrCreate(
                ['workplace_id' => $workplace->id],
                ['shift_id' => $shift->id],
            );
        }

        $workDates = $this->recentWorkDates(5);
        foreach ($employees->values() as $index => $employee) {
            foreach ($workDates as $dayIndex => $date) {
                if (($dayIndex + $index) % 5 === 4) {
                    continue;
                }

                $this->seedAttendance(
                    $employee,
                    $index === 2 ? $studio : $headquarters,
                    $date,
                    'completed',
                    '08:'.sprintf('%02d', 40 + ($index * 5)).':00',
                    '17:'.sprintf('%02d', ($index * 7) % 60).':00',
                );
            }
        }

        $today = CarbonImmutable::today();
        $jordan = $employees['jordan@clockin.test'];
        $sam = $employees['sam@clockin.test'];
        $taylor = $employees['taylor@clockin.test'];
        $casey = $employees['casey@clockin.test'];

        $this->seedAttendance($jordan, $headquarters, $today, 'clocked_in', '08:57:00');
        $breakAttendance = $this->seedAttendance($sam, $headquarters, $today, 'on_break', '07:03:00');
        $breakAttendance->breaks()->updateOrCreate(
            ['started_at' => $today->setTime(12, 0)],
            ['ended_at' => null],
        );
        $this->seedAttendance($taylor, $studio, $today, 'completed', '08:48:00', '13:42:00');

        $missingDate = $workDates[1];
        $reason = 'Forgot to clock in after arriving at the office.';
        $correctionExists = CorrectionRequest::query()
            ->where('user_id', $casey->id)
            ->whereDate('requested_work_date', $missingDate->toDateString())
            ->where('reason', $reason)
            ->exists();

        if (! $correctionExists) {
            CorrectionRequest::query()->create([
                'user_id' => $casey->id,
                'requested_work_date' => $missingDate->toDateString(),
                'workplace_id' => $headquarters->id,
                'requested_clocked_in_at' => $missingDate->setTime(9, 5),
                'requested_clocked_out_at' => $missingDate->setTime(17, 0),
                'reason' => $reason,
            ]);
        }
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function recentWorkDates(int $count): array
    {
        $dates = [];
        $date = CarbonImmutable::today()->subDay();

        while (count($dates) < $count) {
            if ($date->isWeekday()) {
                $dates[] = $date;
            }

            $date = $date->subDay();
        }

        return array_reverse($dates);
    }

    private function seedAttendance(
        User $employee,
        Workplace $workplace,
        CarbonImmutable $date,
        string $status,
        string $clockInTime,
        ?string $clockOutTime = null,
    ): AttendanceRecord {
        $record = AttendanceRecord::query()
            ->where('user_id', $employee->id)
            ->whereDate('work_date', $date->toDateString())
            ->first() ?? new AttendanceRecord([
                'user_id' => $employee->id,
                'work_date' => $date->toDateString(),
            ]);

        $record->fill([
            'workplace_id' => $workplace->id,
            'status' => $status,
            'clocked_in_at' => $date->setTimeFromTimeString($clockInTime),
            'clocked_out_at' => $clockOutTime ? $date->setTimeFromTimeString($clockOutTime) : null,
            'clock_in_latitude' => $workplace->latitude,
            'clock_in_longitude' => $workplace->longitude,
            'clock_out_latitude' => $clockOutTime ? $workplace->latitude : null,
            'clock_out_longitude' => $clockOutTime ? $workplace->longitude : null,
        ])->save();

        return $record;
    }
}
