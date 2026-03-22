<?php

declare(strict_types=1);

namespace App\Commands;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

use function Laravel\Prompts\text;

class BuildCommand extends Command implements SignalableCommandInterface
{
    protected $signature = 'app:build
                            {name? : The build name}
                            {--build-version= : The build version, if not provided it will be asked}
                            {--timeout=300 : The timeout in seconds or 0 to disable}';

    protected $description = 'Build a single file executable';

    private ConsoleOutputInterface $originalOutput;

    private ?string $buildDirectory = null;

    public function handle(): int
    {
        $this->title('Building process');

        $name = $this->input->getArgument('name') ?? $this->getBinary();
        $version = $this->option('build-version') ?: text('Build version?', default: $this->app['config']->get('app.version'));

        $exception = null;

        try {
            $this->buildDirectory = $this->prepareBuildDirectory($version);
            $this->installRuntimeDependencies($this->buildDirectory);
            $this->compile($name, $this->buildDirectory);
        } catch (Throwable $exception) {
            //
        } finally {
            $this->clear();
        }

        if ($exception !== null) {
            throw $exception;
        }

        $this->output->writeln(sprintf('    Compiled successfully: <fg=green>%s</>', $this->app->buildsPath($name)));

        return self::SUCCESS;
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        if (! $output instanceof ConsoleOutputInterface) {
            throw new RuntimeException('Console output is required to build the PHAR.');
        }

        return parent::run($input, $this->originalOutput = $output);
    }

    public function getSubscribedSignals(): array
    {
        return defined('SIGINT') ? [\SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int
    {
        if (defined('SIGINT') && $signal === \SIGINT) {
            $this->clear();
        }

        return self::SUCCESS;
    }

    private function prepareBuildDirectory(string $version): string
    {
        $buildDirectory = storage_path('app/build-workspace');

        $this->task('   1. Preparing <fg=yellow>isolated build workspace</>', function () use ($buildDirectory, $version) {
            File::deleteDirectory($buildDirectory);
            File::makeDirectory($buildDirectory, 0755, true);

            foreach (['app', 'bootstrap', 'config', 'resources'] as $directory) {
                File::copyDirectory(base_path($directory), $buildDirectory.DIRECTORY_SEPARATOR.$directory);
            }

            File::copy(base_path('fic'), $buildDirectory.DIRECTORY_SEPARATOR.'fic');
            File::copy(base_path('VERSION'), $buildDirectory.DIRECTORY_SEPARATOR.'VERSION');
            File::copy(base_path('box.json'), $buildDirectory.DIRECTORY_SEPARATOR.'box.json');
            File::copy(base_path('composer.build.json'), $buildDirectory.DIRECTORY_SEPARATOR.'composer.json');
            File::copy(base_path('composer.build.lock'), $buildDirectory.DIRECTORY_SEPARATOR.'composer.lock');

            $config = include $buildDirectory.DIRECTORY_SEPARATOR.'config/app.php';
            $config['env'] = 'production';
            $config['version'] = $version;

            File::put(
                $buildDirectory.DIRECTORY_SEPARATOR.'config/app.php',
                '<?php return '.var_export($config, true).';'.PHP_EOL,
            );

            $box = json_decode(File::get($buildDirectory.DIRECTORY_SEPARATOR.'box.json'), true, flags: JSON_THROW_ON_ERROR);
            $box['main'] = $this->getBinary();

            File::put(
                $buildDirectory.DIRECTORY_SEPARATOR.'box.json',
                json_encode($box, JSON_THROW_ON_ERROR),
            );
        });

        return $buildDirectory;
    }

    private function installRuntimeDependencies(string $buildDirectory): void
    {
        $this->task('   2. Installing <fg=yellow>runtime dependencies</>', function () use ($buildDirectory) {
            $process = new Process(
                ['composer', 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress', '--optimize-autoloader'],
                $buildDirectory,
                null,
                null,
                $this->getTimeout(),
            );

            $process->mustRun(function (string $type, string $buffer) {
                if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL) {
                    $type === Process::ERR ? $this->error($buffer) : $this->line($buffer);
                }
            });
        });
    }

    private function compile(string $name, string $buildDirectory): void
    {
        if (! File::exists($this->app->buildsPath())) {
            File::makeDirectory($this->app->buildsPath());
        }

        $boxBinary = windows_os() ? '.\\box.bat' : './box';

        $process = new Process(
            array_merge(
                [$boxBinary, 'compile', '--working-dir='.$buildDirectory, '--config='.$buildDirectory.'/box.json', '--no-parallel'],
                $this->getExtraBoxOptions(),
            ),
            base_path('vendor/laravel-zero/framework/bin'),
            null,
            null,
            $this->getTimeout(),
        );

        $section = tap($this->originalOutput->section())->write('');

        $progressBar = new ProgressBar(
            $this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL ? new NullOutput : $section,
            25,
        );

        $progressBar->setProgressCharacter("\xF0\x9F\x8D\xBA");

        $process->start();

        foreach ($process as $type => $data) {
            $progressBar->advance();

            if ($this->output->getVerbosity() > OutputInterface::VERBOSITY_NORMAL) {
                $process::OUT === $type ? $this->info($data) : $this->error($data);
            }
        }

        $progressBar->finish();
        $section->clear();

        $this->task('   3. <fg=yellow>Compile</> into a single file');

        $this->output->newLine();

        $pharPath = $buildDirectory.DIRECTORY_SEPARATOR.$this->getBinary().'.phar';

        if (! File::exists($pharPath)) {
            throw new RuntimeException('Failed to compile the application.');
        }

        File::move($pharPath, $this->app->buildsPath($name));
    }

    private function clear(): void
    {
        if ($this->buildDirectory !== null) {
            File::deleteDirectory($this->buildDirectory);
            $this->buildDirectory = null;
        }
    }

    private function getBinary(): string
    {
        return str_replace(["'", '"'], '', Artisan::artisanBinary());
    }

    private function getTimeout(): ?float
    {
        if (! is_numeric($this->option('timeout'))) {
            throw new \InvalidArgumentException('The timeout value must be a number.');
        }

        $timeout = (float) $this->option('timeout');

        return $timeout > 0 ? $timeout : null;
    }

    private function getExtraBoxOptions(): array
    {
        $extraBoxOptions = [];

        if ($this->output->isDebug()) {
            $extraBoxOptions[] = '--debug';
        }

        return $extraBoxOptions;
    }

    public function __destruct()
    {
        $this->clear();
    }
}
