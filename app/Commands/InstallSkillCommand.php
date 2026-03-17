<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class InstallSkillCommand extends Command
{
    protected $signature = 'install-skill';

    protected $description = 'Install the Fatture in Cloud agent skill for Claude Code';

    public function handle(): int
    {
        $this->info('Installing Fatture in Cloud skill for Claude Code...');

        $process = new Process(['npx', '-y', 'skills', 'add', '16bitsrl/fattureincloud-cli']);
        $process->setTimeout(120);

        if (Process::isTtySupported()) {
            $process->setTty(true);
        }

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('Skill installation failed.');
            $this->line('You can install it manually by copying the skills/ directory.');

            return self::FAILURE;
        }

        $this->info('Skill installed successfully.');

        return self::SUCCESS;
    }
}
