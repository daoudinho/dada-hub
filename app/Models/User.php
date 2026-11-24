<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    /** @var list<string>|null modules ouverts à ce compte (lus une fois par requête) */
    private ?array $moduleSlugs = null;

    /** @var array<string, list<string>> sous-modules ouverts, par module (lus une fois par requête) */
    private array $moduleFeatures = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /** L'administrateur a accès à tous les modules ; les autres, à ceux qu'il leur a ouverts. */
    public function canAccessModule(string $slug): bool
    {
        return $this->isAdmin() || in_array($slug, $this->moduleSlugs(), true);
    }

    /**
     * @return list<string>
     */
    public function moduleSlugs(): array
    {
        return $this->moduleSlugs ??= DB::table('module_user')->where('user_id', $this->id)->pluck('module')->all();
    }

    /** Sous-module ouvert à ce compte (administrateur compris : ses sous-modules se règlent aussi dans la page Accès). */
    public function hasModuleFeature(string $module, string $feature): bool
    {
        return in_array($feature, $this->moduleFeatures($module), true);
    }

    /**
     * @return list<string>
     */
    public function moduleFeatures(string $module): array
    {
        return $this->moduleFeatures[$module] ??= DB::table('module_feature_user')
            ->where('user_id', $this->id)->where('module', $module)->pluck('feature')->all();
    }

    /**
     * Remplace les sous-modules ouverts à ce compte.
     *
     * @param  array<string, list<string>>  $features  module => sous-modules
     */
    public function syncModuleFeatures(array $features): void
    {
        DB::transaction(function () use ($features) {
            DB::table('module_feature_user')->where('user_id', $this->id)->delete();
            $now = now();
            foreach ($features as $module => $keys) {
                foreach (array_unique($keys) as $feature) {
                    DB::table('module_feature_user')->insert(['user_id' => $this->id, 'module' => $module, 'feature' => $feature, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        });

        $this->moduleFeatures = [];
    }

    /**
     * Remplace la liste des modules ouverts à ce compte.
     *
     * @param  list<string>  $slugs
     */
    public function syncModules(array $slugs): void
    {
        DB::transaction(function () use ($slugs) {
            DB::table('module_user')->where('user_id', $this->id)->delete();
            $now = now();
            DB::table('module_user')->insert(array_map(
                fn (string $slug) => ['user_id' => $this->id, 'module' => $slug, 'created_at' => $now, 'updated_at' => $now],
                array_values(array_unique($slugs)),
            ));
        });

        $this->moduleSlugs = null;
    }
}
