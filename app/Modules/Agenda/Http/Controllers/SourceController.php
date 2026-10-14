<?php

namespace App\Modules\Agenda\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Agenda\Categories;
use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Gestion des pages agenda consultées lors des scans.
 */
class SourceController extends Controller
{
    public function index(): View
    {
        return view('agenda::sources', [
            'sources' => Source::withCount('events')->orderByDesc('active')->orderBy('category')->orderBy('name')->get(),
            'categories' => Categories::LABELS,
            'lastScan' => Scan::latest('id')->first(),
        ]);
    }

    public function store(Request $r): RedirectResponse
    {
        $data = $r->validateWithBag('create', [
            'name' => 'required|string|max:120',
            'url' => 'required|url:http,https|max:500|unique:agenda_sources,url',
            'category' => ['nullable', Rule::in(Categories::all())],
            'scan_every_days' => 'nullable|integer|min:1|max:365',
        ]);

        $source = Source::create([
            'active' => true,
            'scan_every_days' => $data['scan_every_days'] ?? config('agenda.default_scan_every_days'),
        ] + $data);

        return to_route('agenda.sources.index')->with('status', '« '.$source->name.' » ajoutée.');
    }

    public function update(Request $r, Source $source): RedirectResponse
    {
        $data = $r->validateWithBag('edit-'.$source->id, [
            'name' => 'sometimes|required|string|max:120',
            'url' => ['sometimes', 'required', 'url:http,https', 'max:500', Rule::unique('agenda_sources', 'url')->ignore($source)],
            'category' => ['sometimes', 'nullable', Rule::in(Categories::all())],
            'active' => 'sometimes|boolean',
            'scan_every_days' => 'sometimes|required|integer|min:1|max:365',
        ]);

        $isToggle = array_keys($data) === ['active'];

        if (isset($data['url']) && $data['url'] !== $source->url) {
            $data['content_hash'] = null;
        }

        if (! empty($data['active']) && ! $source->active) {
            $data['failures'] = 0; // réactivation manuelle : on repart de zéro
        }

        $source->update($data);

        $message = $isToggle
            ? '« '.$source->name.' » '.($source->active ? 'activée.' : 'désactivée.')
            : '« '.$source->name.' » modifiée.';

        return to_route('agenda.sources.index')->with('status', $message);
    }

    public function destroy(Source $source): RedirectResponse
    {
        $source->delete();

        return to_route('agenda.sources.index')->with('status', '« '.$source->name.' » supprimée.');
    }
}
