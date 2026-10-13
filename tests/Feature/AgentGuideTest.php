<?php

it('shows an agent-oriented overview when run without arguments', function () {
    $process = runFic([]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())
        ->toContain('fic skill')
        ->toContain('fic auth:login')
        ->toContain('fic api:list')
        ->toContain('--help')
        ->toContain('Not signed in')
        ->not->toContain("\e[")
        ->not->toContain('api:create-archive-document');
});

it('shows the same overview for --help without a command', function () {
    $process = runFic(['--help']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('fic skill');
});

it('reports the signed-in user and default company in the overview', function () {
    $configHome = sys_get_temp_dir().'/fic-tests-'.bin2hex(random_bytes(6));
    mkdir($configHome.'/fattureincloud-cli', 0700, true);
    file_put_contents($configHome.'/fattureincloud-cli/auth.json', json_encode([
        'access_token' => 'x',
        'user_name' => 'Mario Rossi',
        'user_email' => 'mario@example.com',
    ]));
    file_put_contents($configHome.'/fattureincloud-cli/config.json', json_encode(['company_id' => 42]));

    $output = runFic([], configHome: $configHome)->getOutput();

    expect($output)
        ->toContain('Signed in as Mario Rossi (mario@example.com)')
        ->toContain('42')
        ->not->toContain('Not signed in');
});

it('prints the bundled agent skill', function () {
    $process = runFic(['skill']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())
        ->toContain('# fic: CLI for Fatture in Cloud')
        ->toContain('references/xml-import.md');
});

it('lists and prints skill references', function () {
    $list = runFic(['skill', '--list']);

    expect($list->getExitCode())->toBe(0)
        ->and($list->getOutput())
        ->toContain('api-basics')
        ->toContain('faqs-and-troubleshooting')
        ->toContain('workflows')
        ->toContain('xml-import');

    $reference = runFic(['skill', 'xml-import']);

    expect($reference->getExitCode())->toBe(0)
        ->and($reference->getOutput())
        ->toBe(file_get_contents(base_path('skills/fattureincloud/references/xml-import.md')));
});

it('fails with the available references when one is unknown', function () {
    $process = runFic(['skill', 'nope']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput().$process->getOutput())
        ->toContain('nope')
        ->toContain('xml-import');
});
