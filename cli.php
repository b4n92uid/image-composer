<?php

require __DIR__.'/vendor/autoload.php';

use Symfony\Component\Console\Application;
use App\Command\ImageComposer;

$application = new Application();

$application->add(new ImageComposer());

$application->setDefaultCommand('compose', true);
$application->run();
