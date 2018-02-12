<?php
require_once __DIR__.'/vendor/autoload.php';

if (count($argv) < 4) {
    echo "Usage: run.php <templateBaseDir> <defFile> <outFile> (<releaseFile>)".PHP_EOL;
    die;
}

$t = new \Graviton\ComposeTranspiler\Transpiler($argv[1]);
if (isset($argv[4])) {
    $t->setReleaseFile($argv[4]);
}

$t->setGenerateEnvList(true);

$t->transpile($argv[2], $argv[3]);
