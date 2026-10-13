<?php

use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

/**
 * Runs the real `fic` entry point in a subprocess (no TTY, like an agent would)
 * with an isolated config directory.
 */
function runFic(array $arguments, array $env = [], ?string $configHome = null): Process
{
    $configHome ??= sys_get_temp_dir().'/fic-tests-'.bin2hex(random_bytes(6));

    $process = new Process(
        [PHP_BINARY, base_path('fic'), ...$arguments],
        base_path(),
        ['XDG_CONFIG_HOME' => $configHome, ...$env],
        timeout: 30,
    );

    $process->run();

    return $process;
}
