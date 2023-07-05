<?php
declare(strict_types=1);

use Jobby\Jobby;

//
// Add this line to your crontab file:
//
// * * * * * cd /path/to/project && php jobby.php 1>> /dev/null 2>&1
//

require_once __DIR__ . '/../vendor/autoload.php';

$jobby = new Jobby();

$jobby->add('CommandExample', ['command' => 'ls', 'schedule' => '* * * * *', 'output' => 'logs/command.log', 'enabled' => true]);

$jobby->add('ClosureExample', ['command' => function () {
    echo "I'm a function!\n";

    return true;
}, 'schedule' => '* * * * *', 'output' => 'logs/closure.log', 'enabled' => true]);

$jobby->run();
