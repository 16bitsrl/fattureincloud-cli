<?php

use Tests\TestCase;

it('shows auth status as json', function () {
    /** @var TestCase $this */
    $this->artisan('auth:status', ['--json' => true])->assertExitCode(0);
});
