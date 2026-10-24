<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'customer@hpanel.test'],
            [
                'name' => 'Demo Customer',
                'password' => Hash::make('password'),
                'role' => UserRole::CUSTOMER,
                'status' => UserStatus::ACTIVE,
                'email_verified_at' => now(),
            ]
        );
    }
}

