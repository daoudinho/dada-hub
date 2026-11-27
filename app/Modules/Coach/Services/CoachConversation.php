<?php

namespace App\Modules\Coach\Services;

use App\Models\User;
use App\Modules\Coach\Jobs\WriteAutoReviewJob;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\Message;
use App\Modules\Coach\Models\RunningActivity;
use App\Modules\Coach\Models\RunningSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Déroulé d'un échange avec le coach : message de l'athlète, fiche à jour si elle a changé,
 * réponse de Claude, exécution des outils demandés, jusqu'à la réponse finale.
 *
 * L'historique n'est jamais réécrit (ajouts seulement) : les blocs de réflexion du modèle restent valides
 * et le préfixe de la conversation reste en cache.
 */
class CoachConversation
{
    public function __construct(
        private ClaudeClient $claude,
        private AthleteContext $context,
        private CoachTools $tools,
    ) {}

    public function start(User $user, string $kind, string $title, ?RunningSession $session = null, ?RunningActivity $activity = null): Conversation
    {
        $conversation = new Conversation([
            'kind' => $kind,
            'title' => $title,
            'session_id' => $session?->id,
            'activity_id' => $activity?->id,
            'status' => in_array($kind, ['debrief', 'cancellation'], true) ? 'pending' : 'open',
        ]);
        $conversation->user()->associate($user)->save();

        return $conversation;
    }

    /** Débrief d'une course ; son bilan sera rédigé sans débrief si l'athlète ne vient pas (WriteAutoReviewJob). */
    public function startDebrief(User $user, RunningActivity $activity): Conversation
    {
        $conversation = $this->start($user, 'debrief', $activity->debriefTitle(), $activity->session, $activity);
        WriteAutoReviewJob::dispatch($conversation->id)->delay(WriteAutoReviewJob::dueAt($conversation));

        return $conversation;
    }

    /**
     * Fait parler le coach. Sans texte, sur une conversation vide, le coach ouvre la discussion.
     *
     * @return Collection<int, Message> nouveaux messages affichables
     */
    public function reply(Conversation $conversation, ?string $text): Collection
    {
        $user = $conversation->user;
        $firstNewId = (int) $conversation->messages()->max('id');

        if (filled($text)) {
            $this->append($conversation, 'user', [['type' => 'text', 'text' => $text]], $text);
        } elseif ($conversation->messages()->doesntExist()) {
            $this->append($conversation, 'user', [['type' => 'text', 'text' => CoachPrompt::kickoff($conversation->kind)]]);
        } elseif ($conversation->messages()->reorder('id', 'desc')->value('role') === 'assistant') {
            return collect();
        }
        // Sinon, la réponse précédente a échoué : on la redemande sans rien ajouter.

        $this->appendContextIfChanged($user, $conversation);

        if ($conversation->status === 'pending') {
            $conversation->update(['status' => 'open']);
        }

        try {
            $this->converse($user, $conversation);
        } catch (\Throwable $e) {
            // La fiche envoyée n'a pas abouti : elle sera renvoyée au prochain message.
            $conversation->update(['context_hash' => null]);

            throw $e;
        }

        return $conversation->messages()->where('id', '>', $firstNewId)->whereNotNull('display_text')->get();
    }

    /**
     * Bilan sans débrief : consigne cachée au coach, qui rédige le bilan à partir des données et du ressenti déjà saisi,
     * puis laisse un mot à l'athlète. La conversation est ensuite close.
     */
    public function autoReview(Conversation $conversation): void
    {
        $user = $conversation->user;
        $this->append($conversation, 'user', [['type' => 'text', 'text' => CoachPrompt::autoReview()]]);
        $this->appendContextIfChanged($user, $conversation);

        try {
            $this->converse($user, $conversation);
        } catch (\Throwable $e) {
            $conversation->update(['context_hash' => null]);

            throw $e;
        }

        $conversation->update(['status' => 'closed']);
    }

    private function converse(User $user, Conversation $conversation): void
    {
        $fitness = $user->hasModuleFeature('coach', 'fitness');

        for ($round = 0; $round <= (int) config('coach.ai.max_tool_rounds'); $round++) {
            $response = $this->claude->send(CoachPrompt::system($fitness), $this->history($conversation), $this->tools->definitions($fitness));

            $visibleText = collect($response['content'])->where('type', 'text')->pluck('text')->implode("\n\n");
            if ($response['stop_reason'] === 'refusal' && trim($visibleText) === '') {
                $visibleText = 'Je ne peux pas répondre à ce message. Reformule-le, ou parlons de ton entraînement.';
            }

            $this->append($conversation, 'assistant', $response['content'], trim($visibleText) !== '' ? $visibleText : null, $response);

            if ($response['stop_reason'] !== 'tool_use') {
                break;
            }

            $results = collect($response['content'])->where('type', 'tool_use')->map(fn (array $call) => $this->runTool($user, $conversation, $call))->values()->all();
            $this->append($conversation, 'user', $results);
        }
    }

    /**
     * Historique envoyé à l'API. Une fiche système restée sans réponse (appel en échec) est ignorée :
     * un message système doit être suivi d'une réponse du coach ou terminer l'historique.
     *
     * @return list<array<string, mixed>>
     */
    private function history(Conversation $conversation): array
    {
        $messages = $conversation->messages()->get()->values();

        return $messages
            ->reject(fn (Message $message, int $index) => $message->role === 'system'
                && isset($messages[$index + 1]) && $messages[$index + 1]->role !== 'assistant')
            ->map(fn (Message $message) => $message->toApi())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $call  bloc tool_use
     * @return array<string, mixed> bloc tool_result
     */
    private function runTool(User $user, Conversation $conversation, array $call): array
    {
        try {
            $content = $this->tools->run($user, $conversation->fresh(), (string) $call['name'], (array) ($call['input'] ?? []));
            $isError = false;
        } catch (ValidationException $e) {
            $content = 'Entrée refusée : '.implode(' ', $e->validator->errors()->all());
            $isError = true;
        } catch (\Throwable $e) {
            // Une panne d'outil ne doit jamais casser la conversation : le coach l'explique à l'athlète.
            Log::error('Coach : échec de l\'outil '.$call['name'], ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
            $content = 'L\'outil '.$call['name'].' a échoué. Dis simplement à l\'athlète que tu n\'as pas pu faire cette action pour le moment, '
                .'et donne-lui ce détail technique à transmettre à la personne qui gère le site : « '.mb_strimwidth($e->getMessage(), 0, 300, '…').' ».';
            $isError = true;
        }

        return ['type' => 'tool_result', 'tool_use_id' => $call['id'], 'content' => $content, 'is_error' => $isError];
    }

    /**
     * Fiche athlète insérée comme message système après le message de l'athlète, si elle a changé
     * depuis la dernière fois (la date du jour en fait partie).
     */
    private function appendContextIfChanged(User $user, Conversation $conversation): void
    {
        $context = $this->context->build($user, $conversation);
        $hash = hash('sha256', $context);

        if ($hash === $conversation->context_hash) {
            return;
        }

        $this->append($conversation, 'system', [['type' => 'text', 'text' => $context]]);
        $conversation->update(['context_hash' => $hash]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @param  array{model?: string, input_tokens?: int, output_tokens?: int}  $usage
     */
    private function append(Conversation $conversation, string $role, array $content, ?string $displayText = null, array $usage = []): Message
    {
        return $conversation->messages()->create([
            'role' => $role,
            'content' => $content,
            'display_text' => $displayText,
            'model' => $usage['model'] ?? null,
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
        ]);
    }
}
