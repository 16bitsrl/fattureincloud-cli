<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class SkillCommand extends Command
{
    protected $signature = 'skill
        {topic? : Reference topic to print (see --list)}
        {--list : List the available reference topics}';

    protected $description = 'Print the agent usage guide (or one of its reference topics)';

    public function handle(): int
    {
        $directory = base_path('skills/fattureincloud');
        // scandir() rather than glob(): glob does not work inside the PHAR.
        $topics = collect(scandir($directory.'/references'))
            ->filter(fn (string $file) => str_ends_with($file, '.md'))
            ->map(fn (string $file) => basename($file, '.md'))
            ->sort()
            ->values();

        if ($this->option('list')) {
            foreach ($topics as $topic) {
                $this->line($topic);
            }

            return self::SUCCESS;
        }

        $topic = $this->argument('topic');

        if ($topic === null) {
            $this->output->write(file_get_contents($directory.'/SKILL.md'));

            return self::SUCCESS;
        }

        $topic = basename($topic, '.md');

        if (! $topics->contains($topic)) {
            $this->error("Unknown topic: {$topic}. Available topics: ".$topics->implode(', '));

            return self::FAILURE;
        }

        $this->output->write(file_get_contents("{$directory}/references/{$topic}.md"));

        return self::SUCCESS;
    }
}
