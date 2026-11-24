<?php

namespace App\Modules\Coach\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Coach\Models\Conversation;
use App\Modules\Coach\Models\Proposal;
use App\Modules\Coach\Services\CoachConversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Chat avec le coach : liste des conversations, fil d'une conversation, envoi d'un message.
 */
class ChatController extends Controller
{
    public function index(Request $request): View
    {
        return view('coach::chat.index', [
            'conversations' => Conversation::ownedBy($request->user())
                ->orderByRaw("case when status = 'pending' then 0 else 1 end")
                ->latest('updated_at')
                ->get(),
        ]);
    }

    /** Nouvelle conversation : le premier échange s'il n'a pas encore eu lieu, sinon une discussion libre. */
    public function store(Request $request, CoachConversation $coach): RedirectResponse
    {
        $user = $request->user();
        $isFirst = Conversation::ownedBy($user)->where('kind', 'onboarding')->doesntExist();

        $conversation = $isFirst
            ? $coach->start($user, 'onboarding', 'Premier échange')
            : $coach->start($user, 'chat', 'Discussion du '.today()->format('d/m'));

        return to_route('coach.chat.show', $conversation);
    }

    public function show(Conversation $conversation): View
    {
        Gate::authorize('view', $conversation);

        return view('coach::chat.show', [
            'conversation' => $conversation,
            'timeline' => $this->timeline($conversation),
        ]);
    }

    /** Message de l'athlète (ou ouverture d'une conversation vide) et réponse du coach, en JSON. */
    public function reply(Request $request, Conversation $conversation, CoachConversation $coach): JsonResponse
    {
        Gate::authorize('update', $conversation);
        $data = $request->validate(['message' => ['nullable', 'string', 'max:4000']]);

        set_time_limit(300);
        $lastProposalId = (int) $conversation->proposals()->max('id');

        try {
            $messages = $coach->reply($conversation, $data['message'] ?? null);
        } catch (Throwable $e) {
            Log::error('Coach : échec de la réponse', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Le coach n\'a pas pu répondre. Réessaie dans un instant.'], 502);
        }

        // Le message de l'athlète est déjà affiché par la page à l'envoi : seuls ceux du coach sont renvoyés.
        $messages = $messages->reject(fn ($message) => $message->role === 'user');
        $proposals = $conversation->proposals()->where('id', '>', $lastProposalId)->get();

        return response()->json([
            'html' => view('coach::chat.items', ['items' => $messages->concat($proposals)->sortBy('created_at')->values()])->render(),
        ]);
    }

    /**
     * Messages affichables et propositions, dans l'ordre chronologique.
     *
     * @return Collection<int, Model>
     */
    private function timeline(Conversation $conversation): Collection
    {
        $messages = $conversation->messages()->whereNotNull('display_text')->get();
        $proposals = $conversation->proposals()->get();

        return $messages->concat($proposals)->sortBy(fn ($item) => [$item->created_at, $item instanceof Proposal ? 1 : 0])->values();
    }
}
