<?php

namespace Tests\Feature\Concerns;

/**
 * Réponses simulées de l'API Claude pour les tests du coach.
 */
trait FakesClaude
{
    protected const CLAUDE_URL = 'https://api.anthropic.com/v1/messages';

    protected function configureClaude(): void
    {
        config(['services.anthropic.key' => 'sk-ant-test', 'coach.ai.model' => 'claude-opus-5-5']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function toolUse(string $id, string $name, array $input): array
    {
        return ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    protected function claudeResponse(array $content, string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => $content,
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 150],
        ];
    }
}
