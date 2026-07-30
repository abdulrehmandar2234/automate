<?php

declare(strict_types=1);

use App\Services\Review\PullRequestRef;

it('parses a github pull request url', function () {
    $pr = PullRequestRef::fromUrl('https://github.com/qureos/api-v3/pull/4949');

    expect($pr)->not->toBeNull();
    expect($pr?->owner)->toBe('qureos');
    expect($pr?->repo)->toBe('api-v3');
    expect($pr?->number)->toBe(4949);
    expect($pr?->slug())->toBe('qureos/api-v3');
    expect($pr?->url())->toBe('https://github.com/qureos/api-v3/pull/4949');
});

it('returns null for a non pull-request url', function () {
    expect(PullRequestRef::fromUrl('https://github.com/qureos/api-v3'))->toBeNull();
    expect(PullRequestRef::fromUrl('just some text'))->toBeNull();
});
