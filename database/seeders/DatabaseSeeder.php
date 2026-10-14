<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Agenda\Database\Seeders\AgendaSeeder;
use App\Modules\Agenda\Database\Seeders\AgendaSourceSeeder;
use App\Modules\Agenda\Models\Event;
use App\Modules\Agenda\Models\Source;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Initialisation rejouable sans risque : lancée à chaque démarrage du conteneur de production
 * (docker/entrypoint.sh), elle ne remplit que ce qui est vide et n'écrase rien.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (Source::query()->doesntExist()) {
            $this->call(AgendaSourceSeeder::class);
        }

        if (Event::query()->doesntExist()) {
            $this->call(AgendaSeeder::class);
        }

        $this->createAdmin();
    }

    /**
     * Premier compte, depuis HUB_ADMIN_EMAIL / HUB_ADMIN_PASSWORD (utile sans accès au terminal du serveur).
     * Un compte existant n'est jamais modifié.
     */
    private function createAdmin(): void
    {
        ['email' => $email, 'password' => $password, 'name' => $name] = config('hub.admin');

        if (blank($email) || blank($password) || User::where('email', $email)->exists()) {
            return;
        }

        $user = new User(['name' => $name ?: strstr($email, '@', true), 'email' => $email, 'password' => Hash::make($password)]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->command?->info('Compte créé : '.$email);
    }
}
