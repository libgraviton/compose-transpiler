<?php
/**
 * main transpiler class
 */
namespace Graviton\ComposeTranspiler;

use Graviton\ComposeTranspiler\OutputProcessor\ComposeOnShell;
use Graviton\ComposeTranspiler\OutputProcessor\KubeKustomize;
use Graviton\ComposeTranspiler\OutputProcessor\OutputProcessorAbstract;
use Graviton\ComposeTranspiler\Replacer\VersionTagReplacer;
use Graviton\ComposeTranspiler\Util\ProfileResolver;
use Graviton\ComposeTranspiler\Util\TranspilerUtils;
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

    public const TEMPLATE_EXTENSION = '.twig';

    /**
     * @var TranspilerUtils
     */
    private $utils;

    /**
     * @var OutputProcessorAbstract
     */
    private $outputProcessor;

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

        // wraps twig and fs operations
        $this->utils = new TranspilerUtils($baseDir, $profilePath, $outputPath);

        // set outputprocessor
        $this->setOutputProcessor($this->utils->getTranspilerSettings());
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
     * @return mixed
     */
    public function getEnvFileName()
    {
        return $this->envFileName;
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
     * @return mixed
     */
    public function getBaseEnvFile()
    {
        return $this->baseEnvFile;
    }

    public function transpile() {
        foreach ($this->utils->getResourcesToTranspile() as $source => $destination) {
            $this->transpileFile($source, $destination);
        }

        if ($this->outputProcessor instanceof OutputProcessorAbstract) {
            $this->outputProcessor->finalize();
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

            $file = $templateName;

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

                if ($this->outputProcessor->addExposeServices() && isset($serviceData['expose']) && is_array($serviceData['expose'])) {
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

        // replace $TAG vars
        $replacer = new VersionTagReplacer($this->releaseFile);
        $replacer->setLogger($this->logger);
        $recipe = $replacer->replaceArray($recipe);

        // let outputprocessor do with that structure what he wants..
        $this->outputProcessor->processFile($this, $destFile, $recipe, $profile);

        // are there any scripts to generate?
        if (isset($profile['scripts']) && is_array($profile['scripts'])) {

            // compose image list
            $imageList = [];
            foreach ($recipe['services'] as $service) {
                if (isset($service['image'])) {
                    $imageList[] = $service['image'];
                }
            }

            $baseScriptData = [
                'recipe' => $recipe,
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

                $this->utils->writeOutputFile($scriptData['filename'], $this->getSingleFile($scriptName, $scriptData, false));
                $this->logger->info('Wrote "'.$scriptData['filename'].'"');
            }
        }
    }

    private function getBaseTemplate($defaultTemplate, $data)
    {
        if (isset($data['template'])) {
            $defaultTemplate = $data['template'];
            unset($data['template']);
        }

        return $this->resolveSingleComponent($defaultTemplate, $data);
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
                $mixin = $this->getSingleFile($mixinName, array_merge($data, $mixinData));
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
                $base = $this->getSingleFile($wrapperName, array_merge($base, $wrapperData));
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
        $services[$currentServiceName.'-expose'] = $this->getSingleFile('_expose', $data);
        return $services;
    }

    /**
     * gets a single template and renders it with data
     *
     * @param string $fileName template
     * @param array $data data
     *
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     * @throws \Exception
     *
     * @return mixed
     */
    private function getSingleFile($fileName, $data = [], $isYaml = true)
    {
        $file = $this->utils->renderTwigTemplate($fileName.self::TEMPLATE_EXTENSION, $data);
        if ($isYaml) {
            try {
                return Yaml::parse($file);
            } catch (ParseException $e) {
                throw new \Exception("Error in YML parsing with body = ".$file, 0, $e);
            }
        }
        return $file;
    }

    private function setOutputProcessor($profile)
    {
        if (isset($profile['outputProcessor']['name'])) {
            switch ($profile['outputProcessor']['name']) {
                case 'kube-kustomize':
                    $this->outputProcessor = new KubeKustomize($this->utils, $profile);
                    break;
            }
        } else {
            $this->outputProcessor = new ComposeOnShell($this->utils, $profile);
        }

        if (!is_null($this->logger)) {
            $this->outputProcessor->setLogger($this->logger);
        }

        $this->outputProcessor->startup();
    }
}
