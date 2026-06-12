<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Bot\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('AdminUserSeeder skipped in production.');

            return;
        }

        AdminUser::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'     => 'Admin',
                'password' => Hash::make('password'),
                'role'     => 'admin',
            ],
        );

        $this->command->info('Default admin created: admin@example.com / password');
    }
}
