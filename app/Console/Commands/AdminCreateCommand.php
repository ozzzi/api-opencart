<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Bot\AdminUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('admin:create')]
#[Description('Create a new admin panel user interactively.')]
final class AdminCreateCommand extends Command
{
    public function handle(): int
    {
        $name = text(
            label: 'Name',
            placeholder: 'John Doe',
            required: true,
            validate: fn (string $v) => mb_strlen($v) < 2 ? 'Name must be at least 2 characters.' : null,
        );

        $email = text(
            label: 'Email',
            placeholder: 'admin@example.com',
            required: true,
            validate: function (string $v): ?string {
                $errors = Validator::make(['email' => $v], ['email' => 'required|email'])->errors();

                if ($errors->has('email')) {
                    return 'Please enter a valid email address.';
                }

                if (AdminUser::where('email', $v)->exists()) {
                    return "An admin with email [{$v}] already exists.";
                }

                return null;
            },
        );

        $rawPassword = password(
            label: 'Password',
            placeholder: 'Min. 8 characters',
            required: true,
            validate: fn (string $v) => mb_strlen($v) < 8 ? 'Password must be at least 8 characters.' : null,
        );

        $role = select(
            label: 'Role',
            options: [
                'admin'   => 'Admin — full access (settings, costs)',
                'manager' => 'Manager — conversations, KB, leads',
            ],
            default: 'admin',
        );

        AdminUser::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($rawPassword),
            'role'     => $role,
        ]);

        $this->info("Admin user created successfully.");
        $this->line("  Email : {$email}");
        $this->line("  Role  : {$role}");

        return self::SUCCESS;
    }
}
