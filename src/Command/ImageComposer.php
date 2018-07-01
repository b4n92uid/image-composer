<?php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use App\Service\Process;
use App\Service\Reader;

class ImageComposer extends Command
{
  protected function configure()
  {
    $this
      ->setName('compose')
      ->setDescription('Compose Image')
      ->addArgument('composer-schema', InputArgument::REQUIRED, 'The composer file schema')
      ->addArgument('database', InputArgument::REQUIRED, 'The database file')
      ->addArgument('format', InputArgument::REQUIRED, 'The output filename format')
      ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit generation', null)
      ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'The output working directory', null);
  }

  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $io = new SymfonyStyle($input, $output);

    $io->title('Image Composer');

    $io->section('Parsing composing schema');
    $composerSchema = $input->getArgument('composer-schema');
    $io->text("> $composerSchema");
    $process = new Process($composerSchema);

    $io->section('Reading database');
    $databaseFile = $input->getArgument('database');
    $io->text("> $databaseFile");
    $database = Reader::readDatabase($databaseFile);

    $io->section('Processing...');
    $format = $input->getArgument('format');

    $limit = $input->getOption('limit');
    $output = $input->getOption('output');

    $io->progressStart(count($database));

    for ($i = 0; $i < count($database); $i++) {
      if ($limit !== null && $i >= $limit)
        break;

      $data = $database[$i];

      $filename = rtrim($output,'/\\') . DIRECTORY_SEPARATOR . $process->resolve($format, $data);
      $process->process($data, $filename);
      $io->progressAdvance(1);
    }

    $io->progressFinish();

    $io->success('Process complete');
  }
}
