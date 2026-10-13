<?php

namespace App\Commands;

use App\Concerns\RendersBanner;
use App\Services\TokenStore;
use LaravelZero\Framework\Commands\Command;

class GuideCommand extends Command
{
    use RendersBanner;

    protected $signature = 'guide';

    protected $description = 'Show how to get started and where to find docs';

    protected function configure(): void
    {
        parent::configure();

        // `fic --help` shows the help of the default command, i.e. this guide.
        $this->setHelp(implode(PHP_EOL, $this->usageLines()));
    }

    public function handle(): int
    {
        if ($this->output->isDecorated()) {
            $this->renderBanner($this->output);
        }

        $this->line('fic '.config('app.version').': command line client for the Fatture in Cloud API.');
        $this->newLine();
        $this->line('Status');
        $this->line('  '.$this->status());
        $this->newLine();

        foreach ($this->usageLines() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    protected function status(): string
    {
        if (! TokenStore::isAuthenticated()) {
            return 'Not signed in. Run: fic auth:login';
        }

        $auth = TokenStore::load();
        $companyId = TokenStore::getCompanyId();

        return sprintf(
            'Signed in as %s (%s), default company: %s',
            $auth['user_name'] ?? 'unknown',
            $auth['user_email'] ?? 'unknown',
            $companyId ?? 'not set, run: fic company:set',
        );
    }

    /**
     * @return list<string>
     */
    protected function usageLines(): array
    {
        return [
            'Get started',
            '  fic auth:login              Sign in through the browser, read-only (or --token=TOKEN)',
            '  fic auth:grant SCOPE        Add a permission when a command asks for it',
            '  fic company:set [ID]        Choose the default company',
            '  fic auth:status --json      Check sign-in state and default company',
            '',
            'Call the API',
            '  fic api:list                Every API command with its HTTP method and path',
            '  fic api:<command> --help    Parameters of a command',
            '  fic api:list-issued-documents --company-id=ID --type=invoice --sort=-date',
            '  fic clients:search TEXT --company-id=ID',
            '  fic einvoice:import PATH --company-id=ID --dry-run',
            '',
            'API commands print JSON. Pass --company-id on every company-scoped call',
            '(read the default with: fic company:current --json).',
            '',
            'Learn more',
            '  fic skill                   Full usage guide: filters, pagination, workflows, errors',
            '  fic skill --list            Reference topics, then: fic skill TOPIC',
            '  fic list                    All commands',
            '  fic install-skill           Install the guide as a skill for your coding agent',
            '  fic upgrade                 Upgrade fic to the latest version',
        ];
    }
}
