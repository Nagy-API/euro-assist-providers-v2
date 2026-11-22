<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

/**
 * Safe local account provisioning: no default or published credentials exist anywhere.
 * The password is typed at a hidden prompt, or generated and written once to a git-ignored
 * private file (never printed to the terminal or logs).
 */
class ProvisionUser extends Command
{
    protected $signature = 'users:provision
        {email : Account e-mail}
        {--name= : Display name (defaults to the e-mail local part)}
        {--role=viewer : admin|editor|viewer (least privilege by default)}
        {--generate : Generate a random password and store it in storage/app/private/provisioned/}';

    protected $description = 'Create a user account locally (hidden password prompt or generated private file).';

    public function handle(AuditLogger $audit): int
    {
        $email = mb_strtolower((string) $this->argument('email'));
        $role = Role::tryFrom((string) $this->option('role'));

        if ($this->option('generate') && app()->environment('production')) {
            $this->error('--generate (password written to a file) is not allowed in production; use the hidden prompt.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['email' => $email, 'role' => $this->option('role'), 'name' => $this->option('name')],
            [
                'email' => ['required', 'email:rfc', Rule::unique('users', 'email')],
                'role' => ['required', Rule::enum(Role::class)],
                'name' => ['nullable', 'string', 'max:255'],
            ]
        );
        if ($validator->fails() || $role === null) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->confirm('This is PRODUCTION. Create the account?')) {
            return self::FAILURE;
        }

        $generated = (bool) $this->option('generate');
        $plain = $generated
            ? Str::password(20, symbols: false)
            : password('Password (min 12 characters, hidden)', validate: fn (string $v) => Validator::make(
                ['p' => $v], ['p' => ['required', Password::min(12)->letters()->numbers()]]
            )->errors()->first('p') ?: null);

        $user = new User([
            'name' => $this->option('name') ?: Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make($plain),
        ]);
        $user->role = $role;
        $user->is_active = true;
        $user->email_verified_at = now();
        $user->save();

        $audit->record('user.provisioned', $user, 'success', ['role' => $role->value], null);

        $this->info("Created {$role->value} account for {$email}.");

        if ($generated) {
            $dir = storage_path('app/private/provisioned');
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            // Random suffix: two different e-mails can never collide on (and overwrite) one file.
            $file = $dir.DIRECTORY_SEPARATOR.Str::slug($email).'-'.Str::lower(Str::random(6)).'.txt';
            file_put_contents($file, "email: {$email}\npassword: {$plain}\n");
            @chmod($file, 0600);
            $this->line('The generated password was saved (not printed) to: '.$file);
            $this->warn('Delete that file after first sign-in and change the password.');
        }

        return self::SUCCESS;
    }
}
