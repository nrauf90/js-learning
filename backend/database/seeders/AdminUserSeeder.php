<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if admin already exists
        $adminExists = User::where('email', 'admin@cashflow.local')->exists();

        if (!$adminExists) {
            $admin = User::create([
                'name' => 'Admin User',
                'email' => 'admin@cashflow.local',
                'password' => Hash::make('admin123'),
                'email_verified_at' => now(),
            ]);

            // is_admin is intentionally not mass-assignable; set it explicitly.
            $admin->forceFill(['is_admin' => true])->save();

            $this->command->info('Admin user created successfully!');
            $this->command->info('Email: admin@cashflow.local');
            $this->command->info('Password: admin123');
            $this->command->warn('Please change the password after first login.');
        } else {
            $this->command->info('Admin user already exists.');
        }
    }
}
