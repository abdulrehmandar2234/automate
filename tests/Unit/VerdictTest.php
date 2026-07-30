<?php

declare(strict_types=1);

use App\Enums\Severity;
use App\Enums\Verdict;
use App\Services\Review\Finding;

it('approves when there are no findings', function () {
    expect(Verdict::fromFindings([]))->toBe(Verdict::Approve);
});

it('approves when only minor findings exist', function () {
    $findings = [
        new Finding('a.php', 1, Severity::Warning, 'x'),
        new Finding('b.php', 2, Severity::Nit, 'y'),
    ];

    expect(Verdict::fromFindings($findings))->toBe(Verdict::Approve);
});

it('requests changes when a blocker is present', function () {
    $findings = [
        new Finding('a.php', 1, Severity::Nit, 'x'),
        new Finding('b.php', 2, Severity::Blocker, 'real bug'),
    ];

    expect(Verdict::fromFindings($findings))->toBe(Verdict::RequestChanges);
});

it('maps verdicts to github events', function () {
    expect(Verdict::Approve->githubEvent())->toBe('APPROVE')
        ->and(Verdict::RequestChanges->githubEvent())->toBe('REQUEST_CHANGES')
        ->and(Verdict::Comment->githubEvent())->toBe('COMMENT');
});
