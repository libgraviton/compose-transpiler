<?php
/**
 * main transpiler class
 */
namespace Graviton\ComposeTranspiler;

use Graviton\ComposeTranspiler\Replacer\EnvInflectReplacer;
use Graviton\ComposeTranspiler\Replacer\VersionTagReplacer;
use Graviton\ComposeTranspiler\Util\EnvFileHandler;
use Graviton\ComposeTranspiler\Util\ProfileResolver;
use Graviton\ComposeTranspiler\Util\TranspilerUtils;
use Graviton\ComposeTranspiler\Util\YamlUtils;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class Transpiler {

    /**
     * @var TranspilerUtils
     */
    private $utils;

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
     * if we should inflect (= replace ENV values in yml instead of writing it into env file)
     *
     * @var bool
     */
    private $inflect = false;

    private $releaseFile;
    private $envFileName;
    private $baseEnvFile;

    /**
     * Transpiler constructor.
     * @param string $baseDir path to the 'base directory', where all templates reside
     * @param string $profilePath the 'input' path - either a single yaml file or a directory with many files
     * @param string $outputPath where to output stuff
     * @param OutputInterface $output
     */
    public function __construct($baseDir, $profilePath, $outputPath, OutputInterface $output)
    {
        $this->logger = new ConsoleLogger($output, $this->loggerVerbosityLevelMap);
        $this->fs = new Filesystem();
        $this->envFileHandler = new EnvFileHandler();
        if (!is_null($this->logger)) {
            $this->envFileHandler->setLogger($this->logger);
        }

        // wraps twig and fs operations
        $this->utils = new TranspilerUtils($baseDir, $profilePath, $outputPath);
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
     * set EnvFileName
     *
     * @param mixed $envFileName envFileName
     *
     * @return void
     */
    public function setEnvFileName($envFileName)
    {
        $this->envFileName = $envFileName;
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

    /**
     * set Inflect
     *
     * @param bool $inflect inflect
     *
     * @return void
     */
    public function setInflect($inflect)
    {
        $this->inflect = $inflect;
    }

    public function transpile() {
        foreach ($this->utils->getResourcesToTranspile() as $source => $destination) {
            $this->transpileFile($source, $destination);
        }
    }

    /**
     * transpile a single file
     *
     * @param string $profileFile the profile file specifying what components this consists of
     * @param string $destFile where to generate to
     *
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     *
     * @return bool
     */
    private function transpileFile($profileFile, $destFile)
    {
        $profile = (new ProfileResolver($profileFile))->resolve();

        // if we find that we have 'version' and 'services' in our file, we assume it's already a recipe -> just output
        if (isset($profile['version']) && isset($profile['services'])) {
            if ($destFile == '-') {
                echo file_get_contents($profileFile);
            } else {
                $this->utils->getFs()->copy($profileFile, $destFile);
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

            $file = $templateName.'.tmpl.yml';

            // multiple services using the same params?
            $instanceCount = 1;
            $i = 1;
            if (isset($serviceData['instances']) && is_numeric($serviceData['instances'])) {
                $instanceCount = (int) $serviceData['instances'];
            }

            while ($i < $instanceCount + 1) {
                $instanceSuffix = '';
                if ($i > 1) {
                    $instanceSuffix = ((string) $i);
                }

                $thisServiceName = $serviceName.$instanceSuffix;

                $addedServiceData = [
                    'instanceSuffix' => $instanceSuffix
                ];
                if (isset($serviceData['forInstance'.((string) $i)]) && is_array($serviceData['forInstance'.((string) $i)])) {
                    $addedServiceData = array_merge(
                        $serviceData['forInstance'.((string) $i)],
                        $addedServiceData
                    );
                }

                $recipe['services'][$thisServiceName] = $this->resolveSingleComponent(
                    $file,
                    array_merge(
                        $serviceData,
                        $addedServiceData
                    )
                );

                if (isset($serviceData['expose']) && is_array($serviceData['expose'])) {
                    $recipe['services'] = $this->addExposeHost($recipe['services'], $thisServiceName, $serviceData['expose']);
                }

                $i++;
            }
        }

        // get footer..
        $footerTemplate = 'footer';
        // additional data for footer
        $footerData = ['profile' => $profile, 'recipe' => $recipe];
        if (isset($profile['footer']) && is_array($profile['footer'])) {
            $footer = $this->getBaseTemplate($footerTemplate, array_merge($profile['footer'], $footerData));
        } else {
            $footer = $this->getBaseTemplate($footerTemplate, $footerData);
        }

        $recipe = \Ckr\Util\ArrayMerger::doMerge($recipe, $footer);

        $renderedYaml = $this->dumpYaml($recipe, $destFile);

        // write generated yaml file

        $this->logger->info('Wrote file "'.$destFile.'"');

        // are there any scripts to generate?
        if (isset($profile['scripts']) && is_array($profile['scripts'])) {

            $parsedYaml = Yaml::parse($renderedYaml);

            // compose image list
            $imageList = [];
            foreach ($parsedYaml['services'] as $service) {
                if (isset($service['image'])) {
                    $imageList[] = $service['image'];
                }
            }

            $baseScriptData = [
                'recipe' => Yaml::parse($renderedYaml),
                'recipePath' => $destFile,
                'envFilePath' => $this->envFileName,
                'imageList' => $imageList,
                'imageListUnique' => array_unique($imageList)
            ];

            foreach ($profile['scripts'] as $scriptName => $scriptData) {
                if (is_null($scriptData)) {
                    $scriptData = [];
                }
                $scriptData = array_merge($baseScriptData, $scriptData);

                if (!isset($scriptData['filename'])) {
                    throw new \LogicException("You must specify a filename - what should be the name of the script.");
                }

                if (isset($scriptData['template'])) {
                    $scriptName = $scriptData['template'];
                }

                $file = $scriptName.'.tmpl';
                $this->utils->writeOutputFile($scriptData['filename'], $this->getSingleFile($file, $scriptData, false));
                $this->logger->info('Wrote "'.$scriptData['filename'].'"');
            }
        }

        // is there an output template? if so, overwrite old one..
        if (isset($profile['outputTemplate'])) {
            // any params?
            if (is_array($profile['outputTemplateParams'])) {
                $recipe = array_merge($recipe, $profile['outputTemplateParams']);
            }

            $content = $this->getSingleFile($profile['outputTemplate'].'.tmpl.yml', $recipe, false);
            $this->dumpFile($content, $destFile);
            $this->logger->info('OVERWROTE "'.$destFile.'" as we have an outputTemplate defined.');
        }

    }

    private function getBaseTemplate($defaultTemplate, $data)
    {
        if (isset($data['template'])) {
            $defaultTemplate = $data['template'];
            unset($data['template']);
        }

        return $this->resolveSingleComponent($defaultTemplate.'.tmpl.yml', $data);
    }

    /**
     * returns the array structure for a given file and component, applying all specified mixins and additions
     *
     * @param string $file the file
     * @param array  $data the data from the profile
     *
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     *
     * @return array the final structure
     */
    private function resolveSingleComponent($file, $data = [])
    {
        $base = $this->getSingleFile($file, $data);

        // mixins? -> stuff that gets merged into the array
        if (isset($data['mixins']) && is_array($data['mixins'])) {
            foreach ($data['mixins'] as $mixinName => $mixinData) {
                if (is_null($mixinData)) {
                    $mixinData = [];
                }
                $mixin = $this->getSingleFile($mixinName.'.tmpl.yml', array_merge($data, $mixinData));
                $base = \Ckr\Util\ArrayMerger::doMerge($base, $mixin);
            }
        }

        // additions itself? (gets transported 1:1)
        if (isset($data['additions']) && is_array($data['additions'])) {
            $base = \Ckr\Util\ArrayMerger::doMerge($base, $data['additions']);
        }

        // a wrapper takes the result of the first template and can create a new one..
        if (isset($data['wrapper']) && is_array($data['wrapper'])) {
            foreach ($data['wrapper'] as $wrapperName => $wrapperData) {
                if (is_null($wrapperData)) {
                    $wrapperData = [];
                }
                $base = $this->getSingleFile($wrapperName.'.tmpl.yml', array_merge($base, $wrapperData));
            }
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
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     *
     * @return service array with exposed added
     */
    private function addExposeHost($services, $currentServiceName, $data)
    {
        $services[$currentServiceName.'-expose'] = $this->getSingleFile('_expose.tmpl.yml', $data);
        return $services;
    }

    /**
     * gets a single template and renders it with data
     *
     * @param string $file template
     * @param array $data data
     *
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     * @throws \Exception
     *
     * @return mixed
     */
    private function getSingleFile($file, $data = [], $isYaml = true)
    {
        $file = $this->utils->renderTwigTemplate($file, $data);
        if ($isYaml) {
            try {
                return Yaml::parse($file);
            } catch (ParseException $e) {
                throw new \Exception("Error in YML parsing with body = ".$file, 0, $e);
            }
        }
        return $file;
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
        if ($this->baseEnvFile) {
            $this->envFileHandler->writeEnvFromArrayNoOverwrite(
                $this->envFileHandler->getValuesFromFile($this->baseEnvFile),
                $this->envFileName
            );
        }

        $this->envFileHandler->writeEnvFromArrayNoOverwrite($vars, $this->envFileName);
    }

    private function dumpFile($content, $file)
    {
        // our replacers
        $replacer = new VersionTagReplacer($this->releaseFile);
        $replacer->setLogger($this->logger);
        $replacer->init();
        $content = $replacer->replace($content);

        $this->utils->writeOutputFile($file, $content);
    }

    /**
     * dumps a yaml file in a nicely formatted way
     *
     * @param array  $content the content
     * @param string $file    target file
     *
     * @throws \Exception
     *
     * @return mixed|string
     */
    private function dumpYaml($content, $file)
    {
        // our replacers
        $replacer = new VersionTagReplacer($this->releaseFile);
        $replacer->setLogger($this->logger);
        $content = $replacer->replaceArray($content);

        if ($this->inflect) {
            // inflect requested
            $replacer = new EnvInflectReplacer($this->baseEnvFile);
            $replacer->setLogger($this->logger);
            $content = $replacer->replaceArray($content);
        }

        $content = YamlUtils::dump($content);

        // do we need to generate env file?
        if (!$this->inflect) {
            $this->generateEnvFile($file, $content);
            $this->logger->info('Wrote file "' . $this->envFileName . '"');
        }

        if ($file == '-') { // stdout
            echo $content;
        } else {
            $this->utils->writeOutputFile($file, $content);
        }

        // return content WITHOUT finalRegexes (so templates have access to all)
        return $content;
    }
}
