<?php
/**
 * main transpiler class
 */
namespace Graviton\ComposeTranspiler;

use Graviton\ComposeTranspiler\Util\EnvFileHandler;
use Graviton\ComposeTranspiler\Util\Patterns;
use Graviton\ComposeTranspiler\Util\YamlUtils;
use Neunerlei\Arrays\Arrays;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

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

    private $outDirectory;

    private $projectName;

    private $fs;

    // here we memorize the current component
    private $currentComponent;

    /**
     * @var \SplFileInfo
     */
    private $currentFile;

    // here we save all configmap members
    private $configMap = [];

    private $resources = [];

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

    /**
     * @param mixed|string $projectName
     */
    public function setProjectName($projectName): void
    {
        $this->projectName = $projectName;
    }

    public function transform()
    {
        // traverse all yml files
        if (is_dir($this->filename)) {
            $finder = Finder::create()
                ->in($this->filename)
                ->name(['*.yml', '*.yaml'])
                ->notName(['kustomization.yaml']);

            $files = iterator_to_array($finder);
        } else {
            $files = [new \SplFileInfo($this->filename)];
        }

        $callable = \Closure::fromCallable([$this, 'traverseArray']);

        foreach ($files as $file) {
            $this->currentFile = $file;
            $this->transformFile($file, $callable);
        }

        $this->loadEnvFiles();

        // write kustomization.yaml
        $this->fs->dumpFile(
            $this->outDirectory.'kustomization.yaml',
            YamlUtils::dump($this->getKustomizationYaml())
        );
    }

    private function transformFile(\SplFileInfo $file, callable $callable) {
        $filename = $file->getPathname();
        $parts = YamlUtils::multiParse($filename);
        $transformed = [];

        // the first job is to traverse all this and see for ${} matches..
        foreach ($parts as $part) {
            $transformed[] = Arrays::mapRecursive($part, $callable);
        }

        // write yaml
        $this->fs->dumpFile(
            $this->outDirectory.$file->getBasename(),
            YamlUtils::multiDump($transformed)
        );

        $this->resources[] = basename($filename);
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

    private function loadEnvFiles() {
        if (!is_dir($this->filename)) {
            return;
        }

        $finder = Finder::create()
            ->in($this->filename)
            ->name(['*.env'])
            ->sortByName(true);

        $files = iterator_to_array($finder);
        $envHandler = new EnvFileHandler();

        foreach ($files as $file) {
            $envs = $envHandler->interpretEnvFile($file->getPathname());
            foreach ($envs as $name => $value) {
                if (isset($this->configMap[$name]) && !empty($value)) {
                    $this->configMap[$name] = $value;
                }
            }
        }
    }

    private function getKustomizationYaml() {
        $yaml = [
            'apiVersion' => 'kustomize.config.k8s.io/v1beta1',
            'kind' => 'Kustomization'
        ];

        $yaml['resources'] = $this->resources;

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
