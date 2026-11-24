<?php

namespace App\Modules\Coach\Jobs;

use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Services\CoachConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Bilan d'une course restée sans débrief : programmé à l'ouverture du débrief (CoachConversation::startDebrief),
 * il fait rédiger le bilan par le coach 48 heures plus tard s'il n'existe toujours pas.
 */
class WriteAutoReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Délai après l'ouverture du débrief (heures). */
    public const AFTER_HOURS = 48;

    /** Nouvel essai en cas d'échec (heures), au plus MAX_ATTEMPTS fois. */
    private const RETRY_HOURS = 6;

    private const MAX_ATTEMPTS = 3;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $conversationId, public int $attempt = 1) {}

    /** Moment où le bilan sera rédigé si l'athlète ne fait pas le débrief. */
    public static function dueAt(Conversation $debrief): Carbon
    {
        return $debrief->created_at->copy()->addHours(self::AFTER_HOURS);
    }

    public function handle(CoachConversation $coach): void
    {
        $conversation = Conversation::with('activity')->find($this->conversationId);
        // Bilan déjà écrit, ou pas encore dû (file synchrone, qui ignore le délai).
        if (! $conversation?->activity || $conversation->activity->review || now()->lt(self::dueAt($conversation))) {
            return;
        }

        try {
            $coach->autoReview($conversation);
        } catch (\Throwable $e) {
            Log::warning('Coach : bilan automatique impossible', ['conversation' => $conversation->id, 'attempt' => $this->attempt, 'error' => $e->getMessage()]);
            if ($this->attempt < self::MAX_ATTEMPTS) {
                self::dispatch($this->conversationId, $this->attempt + 1)->delay(now()->addHours(self::RETRY_HOURS));
            }
        }
    }
}
