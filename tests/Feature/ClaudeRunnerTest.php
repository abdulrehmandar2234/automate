<?php

declare(strict_types=1);

use App\Enums\Verdict;
use App\Services\Review\ClaudeRunner;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    // Fail loudly if the code tries to run the real `claude` binary.
    Process::preventStrayProcesses();
});

/** Fake every process with a claude JSON envelope wrapping the given payload. */
function fakeClaude(array $payload): void
{
    $inner = json_encode($payload, JSON_THROW_ON_ERROR);
    Process::fake(fn () => Process::result(json_encode(['result' => $inner, 'is_error' => false], JSON_THROW_ON_ERROR)));
}

it('parses a claude review into a result', function () {
    fakeClaude([
        'summary' => 'Looks fine',
        'findings' => [
            ['path' => 'app/Foo.php', 'line' => 10, 'severity' => 'nit', 'comment' => 'rename this'],
        ],
    ]);

    $result = resolve(ClaudeRunner::class)->review('/tmp', 'prompt');

    expect($result->summary)->toBe('Looks fine')
        ->and($result->verdict)->toBe(Verdict::Approve)
        ->and($result->findings)->toHaveCount(1)
        ->and($result->findings[0]->path)->toBe('app/Foo.php')
        ->and($result->findings[0]->line)->toBe(10);
});

it('requests changes when claude reports a blocker', function () {
    fakeClaude([
        'summary' => 'Has a bug',
        'findings' => [
            ['path' => 'app/Foo.php', 'line' => 3, 'severity' => 'blocker', 'comment' => 'off-by-one'],
        ],
    ]);

    expect(resolve(ClaudeRunner::class)->review('/tmp', 'prompt')->verdict)->toBe(Verdict::RequestChanges);
});

it('throws a helpful error when claude auth is expired', function () {
    Process::fake(fn () => Process::result(json_encode([
        'is_error' => true,
        'api_error_status' => 401,
        'result' => 'Failed to authenticate. OAuth token expired.',
    ], JSON_THROW_ON_ERROR)));

    resolve(ClaudeRunner::class)->review('/tmp', 'prompt');
})->throws(RuntimeException::class, 'Claude auth failed');
