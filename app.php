#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';

use App\Command\Export;
use App\Command\ProcessTasks;
use Symfony\Component\Console\Application;

$application = new Application();
$application->add( new ProcessTasks() );
$application->add( new Export() );
$application->run();
