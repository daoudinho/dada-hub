<?php

namespace App\Modules\Agenda\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Agenda\Categories;
use App\Modules\Agenda\Models\Event;
use App\Modules\Agenda\Models\Scan;
use App\Modules\Agenda\Services\ScanLauncher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgendaController extends Controller
{
    public function index(): View
    {
        return view('agenda::index');
    }

    public function events(): JsonResponse
    {
        $today = now('Europe/Madrid')->toDateString();

        return response()->json(
            Event::whereDate('starts_on', '>=', $today)
                ->orWhereDate('ends_on', '>=', $today)
                ->orderBy('starts_on')->orderBy('time')
                ->get(['id', 'title', 'starts_on', 'ends_on', 'time', 'place', 'url', 'category'])
        );
    }

    public function store(Request $r): JsonResponse
    {
        $data = $r->validate([
            'title' => 'required|string|max:150',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'nullable|date_format:Y-m-d|after:starts_on',
            'time' => 'nullable|date_format:H:i',
            'place' => 'nullable|string|max:120',
            'url' => 'nullable|url:http,https|max:500',
            'category' => ['required', Rule::in(Categories::all())],
        ]);

        return response()->json(Event::create($data + ['source' => 'manual']), 201);
    }

    public function destroy(Event $event): JsonResponse
    {
        $event->delete();

        return response()->json(['ok' => true]);
    }

    public function scan(Request $r, ScanLauncher $launcher): JsonResponse
    {
        $data = $r->validate([
            'filters' => 'required|array|min:1',
            'filters.*' => Rule::in(array_keys(Categories::FILTERS)),
            'mode' => ['nullable', Rule::in(['sources', 'web'])],
            'force' => 'nullable|boolean',
            'batch' => 'nullable|boolean',
        ]);

        if ($running = $launcher->running()) {
            return response()->json($this->scanPayload($running));
        }

        $scan = $launcher->start($data['filters'], $data['mode'] ?? 'sources', (bool) ($data['force'] ?? false), (bool) ($data['batch'] ?? false));

        return response()->json($this->scanPayload($scan->fresh()), 202);
    }

    public function scanStatus(Scan $scan): JsonResponse
    {
        return response()->json($this->scanPayload($scan));
    }

    /**
     * @return array<string, mixed>
     */
    private function scanPayload(Scan $scan): array
    {
        return $scan->only([
            'id', 'mode', 'batch', 'status', 'found', 'error', 'sources_total', 'sources_done', 'sources_skipped',
            'ai_calls', 'input_tokens', 'output_tokens', 'firecrawl_credits', 'cost_usd',
        ])
            + ['age' => (int) $scan->created_at->diffInSeconds(now())];
    }
}
