<?php
/**
 * deals with .env files
 */

namespace Graviton\ComposeTranspiler\Util;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class EnvFileHandler
{

    /**
     * @var TranspilerUtils
     */
    private $utils;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(TranspilerUtils $utils)
    {
        $this->utils = $utils;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * here we let bash and php interpret the final values of a env file
     * so we can get our values to replace them.

     * @return array the env
     */
    public function interpretEnvFile($filename) {
        $subCmd = [
            'set -o allexport',
            'source '.escapeshellarg($filename),
            PHP_BINARY.' -d variables_order=E -r '.escapeshellarg('echo json_encode($_ENV);')
        ];
        $cmd = [
            'env',
            '-i',
            'bash',
            '--noprofile',
            '--norc',
            '-c',
            implode(';', $subCmd)
        ];

        $process = new Process($cmd);
        $process->run();

        if ($this->logger instanceof LoggerInterface) {
            if ($process->isSuccessful()) {
                $this->logger->info("Executed EnvFileParsing successful", ['cmd' => $cmd]);
            } else {
                $this->logger->warning(
                    'Not successful EnvFileParsing',
                    [
                        'cmd' => $cmd,
                        'output' => $process->getErrorOutput()
                    ]
                );
            }
        }

        $values = json_decode($process->getOutput(), true);

        // cleanup these if they exist
        $cleanups = [
            '_', 'PWD', 'SHLVL', 'CWD'
        ];

        foreach ($cleanups as $cleanup) {
            if (isset($values[$cleanup])) {
                unset($values[$cleanup]);
            }
        }

        return $values;
    }

    public function writeEnvFromArrayNoOverwrite($values, $targetFile)
    {
        if ($this->utils->existsOutputFile($targetFile)) {
            $this->existingFileAdd($values, $targetFile);
        } else {
            $this->utils->writeOutputFile($targetFile, implode(PHP_EOL, $values));
        }
    }

    public function getValuesFromFile($filename)
    {
        $contents = file($this->utils->getOutputFilePath($filename));
        $lines = [];
        foreach ($contents as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            if (strpos($line, '=') > 0 && substr($line, 0, 1) != '#') {
                // value line -> split up and trim!
                $parts = array_map('trim', explode('=', $line));
                $lines[$parts[0]] = $line;
            } else {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    private function existingFileAdd($values, $filename)
    {
        $lines = $this->getValuesFromFile($filename);

        foreach ($lines as $key => $line) {
            if (isset($values[$key])) {
                unset($values[$key]);
            }
        }

        // filter comments from base
        $values = array_filter($values, function($key) {
            return !is_numeric($key);
        }, ARRAY_FILTER_USE_KEY);

        // new stuff to add?
        if (!empty($values)) {
            $newContents = [];
            $newContents[] = '# added on '.date('Y-m-d');

            foreach ($values as $name => $value) {
                if ($this->logger instanceof LoggerInterface) {
                    $this->logger->info('Added new env File entry "' . $name . '"');
                }
                $newContents[] = $value;
            }

            $this->utils->writeOutputFile($filename, PHP_EOL.implode(PHP_EOL, $newContents), true);
        }
    }
}
