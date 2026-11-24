<?php

namespace App\Modules\Coach\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Données personnelles du coach : seul le compte propriétaire (user_id) y a accès.
 * Pour les autres, la donnée « n'existe pas » (404) : on ne révèle pas qu'elle existe.
 */
class OwnerPolicy
{
    public function view(User $user, Model $model): Response
    {
        return $this->owns($user, $model);
    }

    public function update(User $user, Model $model): Response
    {
        return $this->owns($user, $model);
    }

    public function delete(User $user, Model $model): Response
    {
        return $this->owns($user, $model);
    }

    private function owns(User $user, Model $model): Response
    {
        return (int) $model->getAttribute('user_id') === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
