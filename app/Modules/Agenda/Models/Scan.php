<?php

namespace App\Modules\Agenda\Models;

use Illuminate\Database\Eloquent\Model;

class Scan extends Model
{
    protected $table = 'agenda_scans';

    protected $fillable = ['filters', 'mode', 'force', 'status', 'found', 'sources_total', 'sources_done', 'error'];

    protected $casts = ['filters' => 'array', 'force' => 'boolean'];
}
