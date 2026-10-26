<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'panel:create-admin
        {email : Email address for the administrator}
        {password : Secure password for the administrator}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update an administrator account idempotently';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $password = (string) $this->argument('password');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: '{$email}'");

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters long.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'password' => Hash::make($password),
                'role' => UserRole::ADMIN,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);
            $this->info("Administrator user '{$email}' updated successfully.");
        } else {
            User::create([
                'name' => 'Administrator',
                'email' => $email,
                'password' => Hash::make($password),
                'role' => UserRole::ADMIN,
                'email_verified_at' => now(),
            ]);
            $this->info("Administrator user '{$email}' created successfully.");
        }

        return self::SUCCESS;
    }
}
