<?php

namespace App\Modules\Agenda\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Erreurs HTTP qui valent un nouvel essai : réseau, 429 (limite de débit), 5xx.
 */
trait RetriesTransientErrors
{
    protected function isTransient(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && ($e->response->status() === 429 || $e->response->serverError());
    }
}
