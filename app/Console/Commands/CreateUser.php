<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('hub:user {email? : Adresse e-mail} {--name= : Nom affiché} {--password= : Mot de passe (demandé si absent)}')]
#[Description('Crée un compte utilisateur du hub, ou change le mot de passe d\'un compte existant')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $email = $this->argument('email') ?? $this->ask('E-mail');
        $user = User::where('email', $email)->first();
        $name = $this->option('name') ?? $user?->name ?? $this->ask('Nom', strstr($email, '@', true) ?: $email);
        $password = $this->option('password') ?? $this->secret('Mot de passe (8 caractères min.)');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            ['email' => ['required', 'email', 'max:255'], 'name' => ['required', 'string', 'max:255'], 'password' => ['required', Password::defaults()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $isNew = ! $user->exists;
        $user->forceFill(['name' => $name, 'password' => Hash::make($password), 'email_verified_at' => $user->email_verified_at ?? now()])->save();

        $this->info(($isNew ? 'Compte créé' : 'Compte mis à jour').' : '.$user->email);

        return self::SUCCESS;
    }
}
