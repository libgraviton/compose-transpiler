<?php
/**
 * deals with .env files
 */

namespace Graviton\ComposeTranspiler\Util;

use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class EnvFileHandler
{

    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->fs = new Filesystem();
        $this->logger = $logger;
    }

    public function writeEnvFromArrayNoOverwrite($values, $targetFile)
    {
        if (file_exists($targetFile)) {
            $this->existingFileAdd($values, $targetFile);
        } else {
            $this->fs->dumpFile($targetFile, implode(PHP_EOL, $values));
        }
    }

    public function getValuesFromFile($filename)
    {
        $contents = file($filename);
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
                $this->logger->info('Added new env File entry "'.$name.'"');
                $newContents[] = $value;
            }

            $this->fs->appendToFile($filename, PHP_EOL.implode(PHP_EOL, $newContents));
        }
    }
}
