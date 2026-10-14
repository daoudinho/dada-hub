<?php

namespace App\Modules\Agenda\Jobs;

use App\Modules\Agenda\Models\AiRequest;
use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Services\AiClient;
use App\Modules\Agenda\Services\SourceExtraction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Vérifie un lot de l'API Batch d'OpenAI ; se relance tant qu'il n'est pas terminé,
 * puis importe les événements de chaque source.
 */
class PollAiBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const PENDING = ['validating', 'in_progress', 'finalizing', 'cancelling'];

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $scanId) {}

    public function handle(AiClient $ai, SourceExtraction $extraction): void
    {
        $scan = Scan::find($this->scanId);
        if ($scan === null || $scan->status !== 'waiting' || $scan->ai_batch_id === null) {
            return;
        }

        $batch = $ai->openAiRequest()->timeout(60)->get('https://api.openai.com/v1/batches/'.$scan->ai_batch_id);

        if ($batch->failed() || in_array($batch->json('status'), self::PENDING, true)) {
            if ($scan->created_at->lt(now()->subHours(26))) {
                self::failRequests($scan, 'API Batch OpenAI : pas de résultat après 26 h.');

                return;
            }
            self::dispatch($scan->id)->delay(now()->addSeconds((int) config('agenda.batch_poll_seconds')));

            return;
        }

        if ($batch->json('status') !== 'completed') {
            self::failRequests($scan, 'API Batch OpenAI : lot '.$batch->json('status').'.');

            return;
        }

        try {
            $lines = $this->lines($ai, $batch->json('output_file_id'));
        } catch (\Throwable $e) {
            Log::warning('Scan agenda : résultats Batch indisponibles, nouvel essai', ['scan' => $scan->id, 'error' => $e->getMessage()]);
            self::dispatch($scan->id)->delay(now()->addSeconds((int) config('agenda.batch_poll_seconds')));

            return;
        }

        $requests = AiRequest::with('source')->where('scan_id', $scan->id)->where('status', 'submitted')->get()
            ->keyBy(fn (AiRequest $request) => $request->customId());
        $found = 0;

        foreach ($lines as $line) {
            $request = $requests->pull($line['custom_id'] ?? '');
            if ($request === null || $request->source === null) {
                continue;
            }

            try {
                if (($line['response']['status_code'] ?? 0) !== 200) {
                    throw new \RuntimeException('HTTP '.($line['response']['status_code'] ?? '?').' '.($line['response']['body']['error']['message'] ?? $line['error']['message'] ?? ''));
                }
                $result = $ai->parseOpenAiBody($line['response']['body'], (string) config('services.openai.extract_model'), batch: true);
                $found += $extraction->apply($scan->id, $request->source, $request->content_hash, $request->categories, $result);
                $request->update(['status' => 'done']);
            } catch (\Throwable $e) {
                $request->source->markFailed('API Batch OpenAI : '.$e->getMessage());
                $request->update(['status' => 'failed']);
            }
        }

        // Requêtes absentes du fichier de résultats (rejetées par OpenAI : voir error_file_id).
        foreach ($requests as $request) {
            $request->source?->markFailed('API Batch OpenAI : requête sans résultat.');
            $request->update(['status' => 'failed']);
        }

        $scan->increment('found', $found, ['status' => 'done']);
    }

    /**
     * Le lot n'a pas abouti : la faute ne revient pas aux sources, leur compteur d'échecs n'augmente pas.
     * Leur empreinte n'étant pas mise à jour, le prochain scan les ré-analysera.
     */
    public static function failRequests(Scan $scan, string $error): void
    {
        Log::error('Scan agenda : lot Batch en échec', ['scan' => $scan->id, 'error' => $error]);

        $requests = AiRequest::with('source')->where('scan_id', $scan->id)->whereIn('status', ['pending', 'submitted'])->get();
        foreach ($requests as $request) {
            $request->source?->update(['last_status' => 'failed', 'last_error' => $error]);
        }
        AiRequest::whereKey($requests->modelKeys())->update(['status' => 'failed']);

        $scan->update(['status' => 'failed', 'error' => $error]);
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function lines(AiClient $ai, ?string $fileId): iterable
    {
        if ($fileId === null) {
            return [];
        }

        $content = $ai->openAiRequest()->timeout(120)->get('https://api.openai.com/v1/files/'.$fileId.'/content');
        if ($content->failed()) {
            throw new \RuntimeException('API Batch OpenAI : téléchargement des résultats impossible (HTTP '.$content->status().').');
        }

        return collect(preg_split('/\r?\n/', trim($content->body())))
            ->map(fn (string $line) => json_decode($line, true))
            ->filter(fn ($line) => is_array($line))
            ->values();
    }
}
