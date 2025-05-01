<?php

// https://www.youtube.com/watch?v=T9rwW-uySBE
// https://gist.github.com/derickr/d5fbab31f50e414acedbab99ac0fc596

require __DIR__.'/../vendor/autoload.php';

$filter = new PHP_CodeCoverage_Filter;
$filter->addDirectoryToWhitelist( dirname(__DIR__) . '/app');
$filter->addDirectoryToWhitelist( dirname(__DIR__) . '/src');
$filter->addDirectoryToWhitelist( dirname(__DIR__) . '/web');
$filter->removeDirectoryFromWhitelist( dirname(__DIR__) . '/app/cache');

$coverage = new PHP_CodeCoverage(
    null,
    $filter
);

$coverage->setDisableIgnoredLines(true);

$coverage->start($_SERVER['REQUEST_URI']);

function save_coverage()
{
    global $coverage;
    $coverage->stop();
    (new PHP_CodeCoverage_Report_PHP)->process($coverage, __DIR__.'/../crawler/' . bin2hex(random_bytes(16)) . '.cov');
}

register_shutdown_function('save_coverage');

