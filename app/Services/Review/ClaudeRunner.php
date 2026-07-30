<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Enums\Severity;
use App\Enums\Verdict;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Runs the `claude` CLI as an agentic reviewer against a checked-out PR and
 * parses its strict-JSON verdict. Auth is the CLI's own login in dev, or
 * ANTHROPIC_API_KEY (BYOK) when set — headless either way.
 */
class ClaudeRunner
{
    public function review(string $worktreeDir, string $prompt): ReviewResult
    {
        $model = config('review.model');
        $model = is_string($model) ? $model : 'sonnet';

        $result = Process::path($worktreeDir)
            ->env($this->env())
            ->input('') // close stdin so `claude -p` doesn't wait for piped input
            ->timeout(600)
            ->run([
                'claude', '-p', $prompt,
                '--model', $model,
                '--allowedTools', 'Read Grep Glob',
                '--output-format', 'json',
            ]);

        return $this->parse($result->output(), $result->errorOutput());
    }

    /** @return array<string, string> */
    private function env(): array
    {
        $key = config('services.anthropic.api_key');

        return is_string($key) && $key !== '' ? ['ANTHROPIC_API_KEY' => $key] : [];
    }

    private function parse(string $stdout, string $stderr): ReviewResult
    {
        $envelope = json_decode($stdout, true);
        if (! is_array($envelope)) {
            throw new RuntimeException('claude did not return a JSON envelope. '.mb_substr($stderr, 0, 200));
        }

        if (($envelope['is_error'] ?? false) === true || ($envelope['api_error_status'] ?? null) === 401) {
            $reason = (string) ($envelope['result'] ?? 'unknown error');
            if (str_contains(mb_strtolower($reason), 'authenticate')) {
                throw new RuntimeException('Claude auth failed — run `claude` and `/login`, or set ANTHROPIC_API_KEY.');
            }
            throw new RuntimeException("claude reported an error: {$reason}");
        }

        $text = $envelope['result'] ?? null;
        if (! is_string($text)) {
            throw new RuntimeException('claude envelope had no result text.');
        }

        $payload = $this->decodePayload($text);

        $findings = $this->parseFindings($payload['findings'] ?? null);

        return new ReviewResult(
            summary: (string) ($payload['summary'] ?? ''),
            verdict: Verdict::fromFindings($findings),
            findings: $findings,
        );
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $text): array
    {
        $clean = trim($text);
        $clean = (string) preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = (string) preg_replace('/\s*```$/', '', $clean);

        $payload = json_decode($clean, true);

        if (! is_array($payload)) {
            $first = strpos($clean, '{');
            $last = strrpos($clean, '}');
            if ($first === false || $last === false) {
                throw new RuntimeException('review result was not valid JSON.');
            }
            $payload = json_decode(substr($clean, $first, $last - $first + 1), true);
        }

        if (! is_array($payload) || ! isset($payload['summary'])) {
            throw new RuntimeException('review JSON missing required fields.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param  mixed  $raw
     * @return array<int, Finding>
     */
    private function parseFindings($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $findings = [];
        foreach ($raw as $item) {
            if (! is_array($item) || ! isset($item['path'], $item['comment'])) {
                continue;
            }
            $line = $item['line'] ?? null;
            $findings[] = new Finding(
                path: (string) $item['path'],
                line: is_int($line) ? $line : null,
                severity: Severity::fromString(isset($item['severity']) ? (string) $item['severity'] : null),
                comment: (string) $item['comment'],
            );
        }

        return $findings;
    }
}
