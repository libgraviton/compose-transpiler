<?php
namespace Graviton\ComposeTranspiler;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class Transpiler {

    private $baseDir;

    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var \Twig_Environment
     */
    private $twig;

    private $releaseFile;

    private $generateEnvList = false;

    public function __construct($baseDir)
    {
        $this->baseDir = $baseDir;
        $this->baseTmplDir = 'base/';
        $this->componentDir = 'components/';
        $this->mixinsDir = 'mixins/';
        $this->fs = new Filesystem();

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
     * set GenerateEnvList
     *
     * @param bool $generateEnvList generateEnvList
     *
     * @return void
     */
    public function setGenerateEnvList($generateEnvList)
    {
        $this->generateEnvList = $generateEnvList;
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

        $this->dumpYaml($recipe, $destFile);
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
     */
    private function addExposeHost($services, $currentServiceName, $data)
    {
        $services[$currentServiceName.'-expose'] =
            $this->getSingleFile($this->componentDir.'_expose.tmpl.yml', $data);
        return $services;
    }


    private function getSingleFile($file, $data = [])
    {
        $file = $this->twig->load($file)->render($data);
        return Yaml::parse($file);
    }

    private function replaceReleaseTags($content)
    {
        if (is_null($this->releaseFile)) {
            return $content;
        }

        if (!file_exists($this->releaseFile)) {
            throw new \Exception("File '".$this->releaseFile."' does not exist");
        }

        foreach (file($this->releaseFile) as $release) {
            $releaseParts = explode(":", trim($release));
            $content = str_replace($releaseParts[0].':${TAG}', trim($release), $content);
        }

        return $content;
    }

    private function generateEnvFile($file, $content)
    {
        if (!$this->generateEnvList) {
            return;
        }

        preg_match_all('/\$\{([a-z0-9_-]*)\}/i', $content, $matches);

        $vars = array_unique($matches[1]);
        sort($vars);

        file_put_contents($file.'.env', implode("=".PHP_EOL, $vars).'='.PHP_EOL);
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
            $this->generateEnvFile($file, $content);
            $this->fs->dumpFile($file, $content);
        }
    }
}
