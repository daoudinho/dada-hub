<?php

namespace App\Modules\Agenda\Jobs;

use App\Modules\Agenda\Models\AiRequest;
use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Services\AiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Envoie les extractions d'un scan dans un lot de l'API Batch d'OpenAI (moitié prix, résultat sous 24 h).
 * https://developers.openai.com/api/docs/guides/batch
 */
class SubmitAiBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $scanId) {}

    public function handle(AiClient $ai): void
    {
        $scan = Scan::find($this->scanId);
        $requests = AiRequest::where('scan_id', $this->scanId)->where('status', 'pending')->get();

        if ($scan === null) {
            return;
        }
        if ($requests->isEmpty()) {
            $scan->update(['status' => 'done']);

            return;
        }

        try {
            $jsonl = $requests->map(fn (AiRequest $request) => json_encode([
                'custom_id' => $request->customId(),
                'method' => 'POST',
                'url' => '/v1/responses',
                'body' => $ai->extractionBody($request->prompt, $request->categories),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n");

            $file = $ai->openAiRequest()->timeout(120)
                ->attach('file', $jsonl, 'agenda-scan-'.$scan->id.'.jsonl')
                ->post('https://api.openai.com/v1/files', ['purpose' => 'batch']);
            if ($file->failed()) {
                throw new RuntimeException('envoi du fichier : HTTP '.$file->status().' '.$file->json('error.message'));
            }

            $batch = $ai->openAiRequest()->timeout(60)->post('https://api.openai.com/v1/batches', [
                'input_file_id' => $file->json('id'),
                'endpoint' => '/v1/responses',
                'completion_window' => '24h',
                'metadata' => ['agenda_scan' => (string) $scan->id],
            ]);
            if ($batch->failed()) {
                throw new RuntimeException('création du lot : HTTP '.$batch->status().' '.$batch->json('error.message'));
            }
        } catch (\Throwable $e) {
            Log::error('Scan agenda : envoi du lot Batch en échec', ['scan' => $scan->id, 'error' => $e->getMessage()]);
            PollAiBatchJob::failRequests($scan, 'API Batch OpenAI : '.$e->getMessage());

            return;
        }

        AiRequest::whereKey($requests->modelKeys())->update(['status' => 'submitted']);
        $scan->update(['status' => 'waiting', 'ai_batch_id' => $batch->json('id')]);

        PollAiBatchJob::dispatch($scan->id)->delay(now()->addSeconds((int) config('agenda.batch_poll_seconds')));
    }
}
