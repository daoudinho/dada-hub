<?php

namespace App\Modules\Recettes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Recettes\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Gestion des étiquettes (filtres de la liste, et choix proposés à l'IA lors des imports).
 */
class TagController extends Controller
{
    public function index(): View
    {
        return view('recettes::tags', ['tags' => Tag::withCount('recipes')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('create', [
            'name' => ['required', 'string', 'max:40', 'unique:recipe_tags,name'],
            'emoji' => ['nullable', 'string', 'max:16'],
        ]);

        $tag = Tag::create($data);

        return to_route('recettes.tags.index')->with('status', '« '.$tag->name.' » ajoutée.');
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $data = $request->validateWithBag('edit-'.$tag->id, [
            'name' => ['required', 'string', 'max:40', Rule::unique('recipe_tags', 'name')->ignore($tag)],
            'emoji' => ['nullable', 'string', 'max:16'],
        ]);

        $tag->update($data);

        return to_route('recettes.tags.index')->with('status', '« '.$tag->name.' » modifiée.');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $tag->delete();

        return to_route('recettes.tags.index')->with('status', '« '.$tag->name.' » supprimée.');
    }
}
