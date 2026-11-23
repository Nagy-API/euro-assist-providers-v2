<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;

/**
 * Operator activation of an existing account (ADR-016): sets a new password through a HIDDEN prompt
 * typed by the account holder or an authorized operator. Never generates, prints, stores or logs the
 * password. Ends every existing session and API token of the account. In production it asks for an
 * explicit confirmation first. Use it only with the owner's authorization for that account.
 */
class SetUserPassword extends Command
{
    protected $signature = 'users:set-password {email : Existing account e-mail}';

    protected $description = 'Set the password of an existing account through a hidden prompt (activation of migrated accounts).';

    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->error('No account with that e-mail.');

            return self::FAILURE;
        }
        if (! $user->is_active) {
            $this->error('The account is deactivated; an administrator must re-activate it first.');

            return self::FAILURE;
        }
        if (app()->environment('production') && ! $this->confirm('This is PRODUCTION. Set a new password for this account (owner-authorized)?')) {
            return self::FAILURE;
        }

        $plain = password('New password (min 12 characters, hidden)', validate: fn (string $v) => Validator::make(
            ['p' => $v], ['p' => ['required', Password::min(12)->letters()->numbers()]]
        )->errors()->first('p') ?: null);
        $again = password('Repeat the new password (hidden)');
        if (! hash_equals($plain, $again)) {
            $this->error('The two entries differ; nothing was changed.');

            return self::FAILURE;
        }

        // Always hash explicitly: the `hashed` cast stores a value that already LOOKS like a hash
        // verbatim, so a typed bcrypt-shaped string (or the activation placeholder) would otherwise
        // become the stored hash itself (review R5).
        $user->password = Hash::make($plain);
        $user->save();
        $user->revokeAllAccess();
        $audit->record('user.password_set_by_operator', $user, 'success', [], null);

        $this->info('Password set. Existing sessions and API tokens of this account were ended.');

        return self::SUCCESS;
    }
}
