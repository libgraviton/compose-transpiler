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
            $this->fs->dumpFile($targetFile, implode("=".PHP_EOL, $values).'='.PHP_EOL);
        }

        $this->logger->info('Wrote file "'.$targetFile.'"');
    }

    public function existingFileAdd($values, $filename) {
        $contents = file($filename);
        $newContents = [];
        foreach ($contents as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            if (strpos($line, '=') > 0 && substr($line, 0, 1) != '#') {
                // value line -> split up and trim!
                $parts = array_map('trim', explode('=', $line));
                $key = $parts[0];

                // unset in base array -> it is set already!
                if (isset($values[$key])) {
                    unset($values[$key]);
                    $newContents[] = implode("=", $parts);
                }
            } else {
                $newContents[] = $line;
            }
        }

        // new stuff to add?
        if (!empty($values)) {
            $newContents[] = '# added on '.date('Y-m-d');

            foreach ($values as $name => $value) {
                $this->logger->info('Added new env File entry "'.$name.'"');
                $newContents[] = $name.'='.$value;
            }
        }

        $this->fs->dumpFile($filename, implode(PHP_EOL, $newContents));
    }
}
