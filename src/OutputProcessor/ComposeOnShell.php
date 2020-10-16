<?php
/**
 * ComposeOnShell outputprocessor
 */
namespace Graviton\ComposeTranspiler\OutputProcessor;

use Graviton\ComposeTranspiler\Transpiler;
use Graviton\ComposeTranspiler\Util\EnvFileHandler;
use Graviton\ComposeTranspiler\Util\YamlUtils;

class ComposeOnShell extends OutputProcessorAbstract {

    /**
     * @var EnvFileHandler
     */
    private $envFileHandler;

    private $envFileName;

    function processFile(Transpiler $transpiler, string $filePath, array $fileContent, array $profile) {
        $this->transpiler = $transpiler;
        $this->envFileHandler = new EnvFileHandler($this->utils);

        // multifile? set env filename
        if ($this->utils->profileIsDirectory()) {
            $this->envFileName = 'dist.env';
        }

        $fileContent = YamlUtils::cleanupYamlArray($fileContent);
        $content = YamlUtils::dump($fileContent);

        // do we need to generate env file?
        $this->generateEnvFile($filePath, $content);
        $this->logger->info('Wrote file "' . $this->envFileName . '"');

        $this->utils->writeOutputFile($filePath, $content);
        $this->logger->info('Wrote file "' . $filePath . '"');

    }

    /**
     * generate the companion env file to the yml file
     *
     * @param string $destFile    destination file as fallback
     * @param string $content the yml content
     */
    private function generateEnvFile($destFile, $content)
    {
        // check filename
        if (is_null($this->envFileName)) {
            $envFile = $destFile;
            if (substr($envFile, -4) == '.yml') {
                $envFile = substr($envFile, 0, -4) . '.env';
            }
            $this->envFileName = $envFile;
        }

        preg_match_all('/\$\{([a-z0-9_-]*)\}/i', $content, $matches);

        $vars = array_unique($matches[1]);
        sort($vars);

        // flip keys & fill lines
        $varContents = array_map(function($value) {
            return $value."=";
        }, $vars);
        $vars = array_combine($vars, $varContents);

        // special env TAG we don't want here..
        if (isset($vars['TAG'])) {
            unset($vars['TAG']);
        }

        // do we have a base file?
        if ($this->transpiler->getBaseEnvFile()) {
            $this->envFileHandler->writeEnvFromArrayNoOverwrite(
                $this->envFileHandler->getValuesFromFile($this->transpiler->getBaseEnvFile()),
                $this->envFileName
            );
        }

        $this->envFileHandler->writeEnvFromArrayNoOverwrite($vars, $this->envFileName);
    }
}
