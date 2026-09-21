<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateTraderCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:create-user
                            {email? : User email address}
                            {password? : User password}
                            {--name=Lead Trader : Trader display name}
                            {--role=admin : User role (admin, trader, viewer)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or reset authorized user account with role (admin, trader, viewer)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) ($this->argument('email') ?: $this->ask('Enter user email address', 'admin@autotrade.io'));
        $password = (string) ($this->argument('password') ?: $this->secret('Enter user password'));
        $name = (string) $this->option('name');
        $role = strtolower((string) $this->option('role'));

        if (! in_array($role, ['admin', 'trader', 'viewer'], true)) {
            $role = 'trader';
        }

        if (empty($password)) {
            $this->error('Password cannot be empty.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'role' => $role,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]
        );

        $this->info("✅ Account configured successfully for [{$user->email}] with role [".strtoupper($user->role).'].');
        $this->line("User ID: {$user->id} | Name: {$user->name} | Role: {$user->role}");

        return self::SUCCESS;
    }
}
