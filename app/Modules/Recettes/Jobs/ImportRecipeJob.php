<?php

namespace App\Modules\Recettes\Jobs;

use App\Modules\Recettes\Models\Recipe;
use App\Modules\Recettes\Models\Tag;
use App\Modules\Recettes\Services\RecipeExtraction;
use App\Modules\Recettes\Services\RecipeWriter;
use App\Services\Ai\AiClient;
use App\Services\Firecrawl\FirecrawlClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Remplit une recette depuis sa page web : Firecrawl récupère la page, l'IA en extrait
 * ingrédients, étapes, portions, temps et étiquettes, puis l'image principale est téléchargée.
 * Le nom saisi par l'utilisateur et ses étiquettes sont conservés.
 */
class ImportRecipeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $recipeId) {}

    public function handle(FirecrawlClient $firecrawl, AiClient $ai, RecipeExtraction $extraction, RecipeWriter $writer): void
    {
        $recipe = Recipe::find($this->recipeId);
        if ($recipe === null || ! $recipe->isImporting() || $recipe->source_url === null) {
            return;
        }

        try {
            $tags = Tag::orderBy('name')->get();
            $page = $firecrawl->scrape($recipe->source_url);
            $result = $ai->extract($extraction->prompt($recipe->source_url, $page->markdown, $tags), RecipeExtraction::SCHEMA_NAME, $extraction->schema($tags));
            ['recipe' => $data, 'tag_ids' => $tagIds] = $extraction->parse($result, $tags);

            Log::info('Recette importée', ['recipe' => $recipe->id, 'model' => $result->model, 'tokens' => $result->inputTokens + $result->outputTokens, 'cost_usd' => $result->cost()]);
        } catch (\Throwable $e) {
            Log::warning('Import de recette en échec', ['recipe' => $recipe->id, 'error' => $e->getMessage()]);
            $recipe->update(['import_status' => 'failed', 'import_error' => mb_substr($e->getMessage(), 0, 500)]);

            return;
        }

        $tagIds = array_values(array_unique([...$recipe->tags()->pluck('recipe_tags.id')->all(), ...$tagIds]));
        $writer->save($recipe, $data + ['tag_ids' => $tagIds, 'import_status' => null, 'import_error' => null]);

        if ($page->imageUrl !== null) {
            $this->importImage($recipe, $page->imageUrl, $writer);
        }
    }

    /** Une image introuvable ou illisible n'empêche pas l'import : la recette reste sans photo. */
    private function importImage(Recipe $recipe, string $url, RecipeWriter $writer): void
    {
        try {
            $response = Http::timeout(30)->withHeaders(['Accept' => 'image/*'])->get($url);
            $body = $response->body();

            if ($response->failed() || strlen($body) > (int) config('recettes.image.max_download_kb') * 1024) {
                throw new RuntimeException('HTTP '.$response->status().', '.strlen($body).' octets');
            }

            $writer->attachImage($recipe, $body);
        } catch (\Throwable $e) {
            Log::warning('Image de recette non importée', ['recipe' => $recipe->id, 'url' => $url, 'error' => $e->getMessage()]);
        }
    }
}
