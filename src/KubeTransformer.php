<?php
/**
 * main transpiler class
 */
namespace Graviton\ComposeTranspiler;

use Graviton\ComposeTranspiler\Util\Patterns;
use Graviton\ComposeTranspiler\Util\YamlUtils;
use Neunerlei\Arrays\Arrays;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class KubeTransformer {

    /**
     * @var LoggerInterface
     */
    private $logger;

    private $filename;

    // filename without extension
    private $projectName;

    private $outDirectory;

    private $fs;

    // here we memorize the current component
    private $currentComponent;

    // here we save all configmap members
    private $configMap = [];

    /**
     * @var array
     */
    private $loggerVerbosityLevelMap = [
        LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL,
        LogLevel::INFO   => OutputInterface::VERBOSITY_NORMAL
    ];

    public function __construct($filename, $outDirectory, OutputInterface $output)
    {
        $this->logger = new ConsoleLogger($output, $this->loggerVerbosityLevelMap);
        $this->fs = new Filesystem();
        $this->filename = $filename;

        // set projectName
        $filenameParts = explode('.', basename($filename));
        $this->projectName = $filenameParts[0];

        $this->outDirectory = $outDirectory;
        if (substr($this->outDirectory, -1) != '/') {
            $this->outDirectory .= '/';
        }
    }

    public function transform()
    {
        $parts = YamlUtils::multiParse($this->filename);
        $transformed = [];

        $callable = \Closure::fromCallable([$this, 'traverseArray']);

        // the first job is to traverse all this and see for ${} matches..
        foreach ($parts as $part) {
            $transformed[] = Arrays::mapRecursive($part, $callable);
        }

        // write yaml
        $this->fs->dumpFile(
            $this->outDirectory.basename($this->filename),
            YamlUtils::multiDump($transformed)
        );

        // write kustimization.yaml
        $this->fs->dumpFile(
            $this->outDirectory.'kustomization.yaml',
            YamlUtils::dump($this->getKustomizationYaml())
        );
    }

    /**
     * handles the transformation of each element in the array
     *
     * @param $currentValue
     * @param $currentKey
     * @param $pathOfKeys
     * @param $inputArray
     * @return mixed
     */
    private function traverseArray($currentValue, $currentKey, $pathOfKeys, $inputArray) {
        // the full path
        $path = implode('.', $pathOfKeys);

        // memorize component
        if ($path == 'metadata.name') {
            $this->currentComponent = $currentValue;
        }

        // match ${VARNAME} stuff
        preg_match_all(Patterns::DOCKER_ENV_VALUES, $currentValue, $matches, PREG_SET_ORDER);
        if (empty($matches)) {
            // no changes
            return $currentValue;
        }

        foreach ($matches as $match) {
            $matchParts = explode(':-', $match[1]);
            $varName = $matchParts[0];
            $default = null;
            if (isset($matchParts[1])) {
                $default = $matchParts[1];
            }

            $this->configMap[$varName] = $default;

            // change braces
            $currentValue = str_replace($match[0], '$('.$varName.')', $currentValue);
        }

        return $currentValue;
    }

    private function getKustomizationYaml() {
        $yaml = [
            'apiVersion' => 'kustomize.config.k8s.io/v1beta1',
            'kind' => 'Kustomization'
        ];

        $yaml['resources'] = [basename($this->filename)];

        // configmap
        $mapEntries = [];
        foreach ($this->configMap as $name => $value) {
            $entry = $name.'=';
            if (!empty($value)) {
                $entry .= $value;
            }
            $mapEntries[] = $entry;
        }

        $yaml['configMapGenerator'] = [];
        $yaml['configMapGenerator'][] = [
            'name' => $this->projectName,
            'literals' => $mapEntries
        ];

        // expose everything as VARs as well...
        $yaml['vars'] = [];
        foreach ($this->configMap as $name => $value) {
            $yaml['vars'][] = [
                'name' => $name,
                'objref' => [
                    'apiVersion' => 'v1',
                    'kind' => 'ConfigMap',
                    'name' => $this->projectName
                ],
                'fieldref' => [
                    'fieldpath' => 'data.'.$name
                ]
            ];
        }

        return $yaml;
    }

}
