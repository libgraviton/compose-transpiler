<?php
/**
 * main transpiler class
 */
namespace Graviton\ComposeTranspiler;

use Graviton\ComposeTranspiler\Util\EnvFileHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class Transpiler {

    private $baseDir;
    private $baseTmplDir;
    private $componentDir;
    private $mixinsDir;
    private $scriptsDir;

    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $loggerVerbosityLevelMap = [
        LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL,
        LogLevel::INFO   => OutputInterface::VERBOSITY_NORMAL
    ];

    /**
     * @var EnvFileHandler
     */
    private $envFileHandler;

    /**
     * @var \Twig_Environment
     */
    private $twig;

    private $releaseFile;
    private $baseEnvFile;

    public function __construct($baseDir, OutputInterface $output)
    {
        $this->baseDir = $baseDir;
        $this->baseTmplDir = 'base/';
        $this->componentDir = 'components/';
        $this->mixinsDir = 'mixins/';
        $this->scriptsDir = 'scripts/';
        $this->logger = new ConsoleLogger($output, $this->loggerVerbosityLevelMap);
        $this->fs = new Filesystem();
        $this->envFileHandler = new EnvFileHandler($this->logger);

        $loader = new \Twig_Loader_Filesystem($this->baseDir);
        $this->twig = new \Twig_Environment($loader);
    }

    /**
     * set ReleaseFile
     *
     * @param mixed $releaseFile releaseFile
     *
     * @return void
     */
    public function setReleaseFile($releaseFile)
    {
        $this->releaseFile = $releaseFile;
    }

    /**
     * set BaseEnvFile
     *
     * @param mixed $baseEnvFile baseEnvFile
     *
     * @return void
     */
    public function setBaseEnvFile($baseEnvFile)
    {
        $this->baseEnvFile = $baseEnvFile;
    }

    public function transpile($profileFile, $destFile)
    {
        $profile = Yaml::parseFile($profileFile);

        // if we find that we have 'version' and 'services' in our file, we assume it's already a recipe -> just output
        if (isset($profile['version']) && isset($profile['services'])) {
            if ($destFile == '-') {
                echo file_get_contents($profileFile);
            } else {
                $this->fs->copy($profileFile, $destFile);
            }
            return true;
        }

        // get header..
        $headerTemplate = 'header';
        if (isset($profile['header']) && is_array($profile['header'])) {
            $recipe = $this->getBaseTemplate($headerTemplate, $profile['header']);
        } else {
            $recipe = $this->getBaseTemplate($headerTemplate, []);
        }

        // compose services
        foreach ($profile['components'] as $service => $serviceData) {
            if (!is_array($serviceData)) {
                $serviceData = [];
            }

            $serviceName = $service;
            if (isset($serviceData['name'])) {
                $serviceName = $serviceData['name'];
            }

            $templateName = $service;
            if (isset($serviceData['template'])) {
                $templateName = $serviceData['template'];
            }

            $file = $this->componentDir.$templateName.'.tmpl.yml';
            $recipe['services'][$serviceName] = $this->resolveSingleComponent($file, $serviceData);

            if (isset($serviceData['expose']) && is_array($serviceData['expose'])) {
                $recipe['services'] = $this->addExposeHost($recipe['services'], $serviceName, $serviceData['expose']);
            }
        }

        // get footer..
        $footerTemplate = 'footer';
        if (isset($profile['footer']) && is_array($profile['footer'])) {
            $footer = $this->getBaseTemplate($footerTemplate, $profile['footer']);
        } else {
            $footer = $this->getBaseTemplate($footerTemplate, []);
        }

        $recipe = \Ckr\Util\ArrayMerger::doMerge($recipe, $footer);

        // write generated yaml file
        $renderedYaml = $this->dumpYaml($recipe, $destFile);
        $this->logger->info('Wrote file "'.$destFile.'"');

        // write env file
        $envFilename = $destFile;
        if (substr($envFilename, -4) == '.yml') {
            $envFilename = substr($envFilename, 0,-4).'.env';
        }
        $this->generateEnvFile($envFilename, $renderedYaml);
        $this->logger->info('Wrote file "'.$envFilename.'"');

        // are there any scripts to generate?
        if (isset($profile['scripts']) && is_array($profile['scripts'])) {
            $baseScriptData = [
                'recipe' =>Yaml::parse($renderedYaml),
                'recipePath' => $destFile,
                'envFilePath' => $envFilename
            ];

            foreach ($profile['scripts'] as $scriptName => $scriptData) {
                if (is_null($scriptData)) {
                    $scriptData = [];
                }
                $scriptData = array_merge($baseScriptData, $scriptData);

                if (!isset($scriptData['filename'])) {
                    throw new \LogicException("You must specify a filename - what should be the name of the script.");
                }

                $scriptDestination = pathinfo($destFile,PATHINFO_DIRNAME).'/'.$scriptData['filename'];
                $file = $this->scriptsDir.$scriptName.'.tmpl';
                $this->fs->dumpFile($scriptDestination, $this->getSingleFile($file, $scriptData, false));
                $this->logger->info('Wrote "'.$scriptDestination.'"');
            }
        }

    }

    private function getBaseTemplate($defaultTemplate, $data)
    {
        if (isset($data['template'])) {
            $defaultTemplate = $data['template'];
            unset($data['template']);
        }

        return $this->resolveSingleComponent($this->baseTmplDir.$defaultTemplate.'.tmpl.yml', $data);
    }

    private function resolveSingleComponent($file, $data = [])
    {
        $base = $this->getSingleFile($file, $data);

        // mixins? -> stuff that gets merged into the array
        if (isset($data['mixins']) && is_array($data['mixins'])) {
            foreach ($data['mixins'] as $mixinName => $mixinData) {
                if (is_null($mixinData)) {
                    $mixinData = [];
                }
                $mixin = $this->getSingleFile($this->mixinsDir.$mixinName.'.tmpl.yml', $mixinData);
                $base = \Ckr\Util\ArrayMerger::doMerge($base, $mixin);
            }
        }

        // additions itself? (gets transported 1:1)
        if (isset($data['additions']) && is_array($data['additions'])) {
            $base = \Ckr\Util\ArrayMerger::doMerge($base, $data['additions']);
        }

        return $base;
    }

    /**
     * adds the *-expose service to a given
     *
     * @param $services
     * @param $currentServiceName
     * @param $data
     *
     * @return service array with exposed added
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    private function addExposeHost($services, $currentServiceName, $data)
    {
        $services[$currentServiceName.'-expose'] =
            $this->getSingleFile($this->componentDir.'_expose.tmpl.yml', $data);
        return $services;
    }

    /**
     * gets a single template and renders it with data
     *
     * @param string $file template
     * @param array $data data
     * @return mixed
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    private function getSingleFile($file, $data = [], $isYaml = true)
    {
        $file = $this->twig->load($file)->render($data);
        if ($isYaml) {
            return Yaml::parse($file);
        }
        return $file;
    }

    /**
     * replaces all ${TAG} variables with the content from the release file
     *
     * @param string $content the compile compose recipe
     * @return mixed replaced
     * @throws \Exception
     */
    private function replaceReleaseTags($content)
    {
        if (!is_null($this->releaseFile)) {
            if (!file_exists($this->releaseFile)) {
                throw new \Exception("File '".$this->releaseFile."' does not exist");
            }

            foreach (file($this->releaseFile) as $release) {
                $releaseParts = explode(":", trim($release));
                $content = str_replace($releaseParts[0].':${TAG}', trim($release), $content);
            }
        }

        // replace missing ${TAG} mit notice!
        preg_match_all('/([a-z0-9-_]*):\$\{TAG\}/i', $content, $matches);
        foreach ($matches[0] as $matched) {
            $this->logger->warning('Replace unset image '.$matched.' with "latest"!');
            $content = str_replace($matched, str_replace('${TAG}', 'latest', $matched), $content);
        }

        return $content;
    }

    private function generateEnvFile($file, $content)
    {
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
        if ($this->baseEnvFile) {
            $this->envFileHandler->writeEnvFromArrayNoOverwrite(
                $this->envFileHandler->getValuesFromFile($this->baseEnvFile),
                $file
            );
        }

        $this->envFileHandler->writeEnvFromArrayNoOverwrite($vars, $file);
    }

    private function dumpYaml($content, $file)
    {
        $content = Yaml::dump($content, 99, 2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK +
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE +
            Yaml::DUMP_OBJECT_AS_MAP
        );

        $content = $this->replaceReleaseTags($content);

        if ($file == '-') { // stdout
            echo $content;
        } else {
            $this->fs->dumpFile($file, $content);
        }

        return $content;
    }
}
