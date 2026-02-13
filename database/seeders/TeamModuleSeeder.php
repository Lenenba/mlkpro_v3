<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TeamModuleSeeder extends Seeder
{
    /**
     * Seed data to validate team members, permissions, and job assignments.
     */
    public function run(): void
    {
        $ownerRoleId = Role::firstOrCreate(
            ['name' => 'owner'],
            ['description' => 'Account owner role']
        )->id;

        $account = User::first();
        if (!$account) {
            $account = User::factory()->create([
                'name' => 'Team Demo',
                'email' => 'team.demo@example.com',
                'role_id' => $ownerRoleId,
            ]);
        }

        $employeeRoleId = Role::where('name', 'employee')->value('id');
        if (!$employeeRoleId) {
            $employeeRoleId = Role::create([
                'name' => 'employee',
                'description' => 'Employee role',
            ])->id;
        }

        $seedMembers = [
            [
                'name' => 'Alex Technician',
                'email' => 'alex.tech@example.com',
                'role' => 'admin',
                'title' => 'Technician',
                'phone' => '+15145550100',
                'permissions' => [
                    'jobs.view',
                    'jobs.edit',
                    'tasks.view',
                    'tasks.create',
                    'tasks.edit',
                    'tasks.delete',
                    'reservations.view',
                    'reservations.queue',
                    'reservations.manage',
                ],
            ],
            [
                'name' => 'Jamie Helper',
                'email' => 'jamie.helper@example.com',
                'role' => 'member',
                'title' => 'Helper',
                'phone' => '+15145550101',
                'permissions' => [
                    'jobs.view',
                    'tasks.view',
                    'tasks.edit',
                    'reservations.view',
                    'reservations.queue',
                ],
            ],
        ];

        $members = collect();
        foreach ($seedMembers as $seedMember) {
            $memberUser = User::firstOrCreate(
                ['email' => $seedMember['email']],
                [
                    'name' => $seedMember['name'],
                    'password' => Hash::make('password'),
                    'role_id' => $employeeRoleId,
                    'email_verified_at' => now(),
                ]
            );

            $memberUser->update([
                'name' => $seedMember['name'],
                'role_id' => $employeeRoleId,
                'email_verified_at' => $memberUser->email_verified_at ?? now(),
            ]);

            $members->push(TeamMember::updateOrCreate(
                [
                    'account_id' => $account->id,
                    'user_id' => $memberUser->id,
                ],
                [
                    'role' => $seedMember['role'],
                    'title' => $seedMember['title'],
                    'phone' => $seedMember['phone'],
                    'permissions' => $seedMember['permissions'],
                    'is_active' => true,
                ]
            ));
        }

        $works = Work::byUser($account->id)->orderByDesc('created_at')->take(4)->get();
        if ($works->isEmpty() || $members->isEmpty()) {
            return;
        }

        $firstMember = $members->first();
        $secondMember = $members->skip(1)->first();

        if ($firstMember) {
            $works->take(2)->each(fn(Work $work) => $work->teamMembers()->syncWithoutDetaching([$firstMember->id]));
        }

        if ($secondMember) {
            $works->skip(1)->take(2)->each(fn(Work $work) => $work->teamMembers()->syncWithoutDetaching([$secondMember->id]));
        }
    }
}
