<?php

declare(strict_types=1);

it('fails on a url that is not a github pull request', function () {
    $this->artisan('review:pr', ['url' => 'not-a-url'])->assertFailed();
});

it('skips a repo that is not in the routing table', function () {
    config(['review.repos' => []]);

    $this->artisan('review:pr', ['url' => 'https://github.com/acme/app/pull/1'])
        ->expectsOutputToContain('Skipped')
        ->assertSuccessful();
});
