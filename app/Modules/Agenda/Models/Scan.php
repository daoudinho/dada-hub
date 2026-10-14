<?php

namespace App\Modules\Agenda\Models;

use App\Modules\Agenda\Services\AiResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Scan extends Model
{
    protected $table = 'agenda_scans';

    protected $fillable = [
        'filters', 'mode', 'force', 'batch', 'ai_batch_id', 'status', 'found', 'error',
        'sources_total', 'sources_done', 'sources_skipped',
        'ai_calls', 'input_tokens', 'output_tokens', 'firecrawl_credits', 'cost_usd',
    ];

    protected $casts = ['filters' => 'array', 'force' => 'boolean', 'batch' => 'boolean', 'cost_usd' => 'float'];

    /**
     * Ajoute la consommation d'un appel (requête SQL atomique : plusieurs jobs écrivent en parallèle).
     * Le coût reste null tant qu'aucun appel n'a un prix connu.
     */
    public static function addUsage(int $scanId, ?AiResult $ai = null, int $firecrawlCredits = 0): void
    {
        $columns = ['firecrawl_credits' => $firecrawlCredits];
        $extra = [];

        if ($ai !== null) {
            $columns += ['ai_calls' => 1, 'input_tokens' => $ai->inputTokens, 'output_tokens' => $ai->outputTokens];
            if (($cost = $ai->cost()) !== null) {
                $extra['cost_usd'] = DB::raw('COALESCE(cost_usd, 0) + '.number_format($cost, 6, '.', ''));
            }
        }

        static::whereKey($scanId)->incrementEach($columns, $extra);
    }
}
