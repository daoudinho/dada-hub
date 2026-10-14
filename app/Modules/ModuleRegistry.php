<?php

namespace App\Modules;

use Illuminate\Support\Collection;

/**
 * Liste des modules enregistrés (pour le dashboard et la navigation).
 */
class ModuleRegistry
{
    /** @var array<string, Module> */
    private array $modules = [];

    public function add(Module $module): void
    {
        $this->modules[$module->slug()] = $module;
    }

    /**
     * @return Collection<string, Module>
     */
    public function all(): Collection
    {
        return collect($this->modules);
    }

    public function get(string $slug): ?Module
    {
        return $this->modules[$slug] ?? null;
    }
}
