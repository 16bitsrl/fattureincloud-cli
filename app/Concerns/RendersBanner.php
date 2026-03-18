<?php

namespace App\Concerns;

use Symfony\Component\Console\Output\OutputInterface;

trait RendersBanner
{
    public function renderBanner(OutputInterface $output): void
    {
        $lines = [
            '  ███████╗ ██╗  ██████╗',
            '  ██╔════╝ ██║ ██╔════╝',
            '  █████╗   ██║ ██║     ',
            '  ██╔══╝   ██║ ██║     ',
            '  ██║      ██║ ╚██████╗',
            '  ╚═╝      ╚═╝  ╚═════╝',
        ];

        $gradient = [25, 26, 27, 33, 39, 45];

        $output->writeln('');

        foreach ($lines as $i => $line) {
            $output->writeln("\e[38;5;{$gradient[$i]}m{$line}\e[0m");
        }

        $output->writeln('');

        $tagline = ' ✦ Fatture in Cloud :: fattureincloud.it ✦ ';
        $output->writeln("\e[48;5;27m\e[97m\e[1m{$tagline}\e[0m");

        $output->writeln('');
    }
}
