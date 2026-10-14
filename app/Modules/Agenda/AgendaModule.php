<?php

namespace App\Modules\Agenda;

use App\Modules\Agenda\Console\DedupeCommand;
use App\Modules\Agenda\Console\ScanCommand;
use App\Modules\Module;

class AgendaModule extends Module
{
    public function slug(): string
    {
        return 'agenda';
    }

    public function name(): string
    {
        return 'Agenda Barcelone';
    }

    public function description(): string
    {
        return 'Concerts, expos, scène, Barça : les événements à venir, avec scan automatique par IA.';
    }

    public function icon(): string
    {
        return '📅';
    }

    public function consoleCommands(): array
    {
        return [ScanCommand::class, DedupeCommand::class];
    }
}
