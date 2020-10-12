<?php
/**
 * utils for transpiler itself and outputprocessors
 */
namespace Graviton\ComposeTranspiler\Util;

use Graviton\ComposeTranspiler\Util\Twig\Extension;
use Symfony\Bridge\Twig\Extension\YamlExtension;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class TranspilerUtils
{

    /**
     * @var Environment
     */
    private $twig;

    /**
     * @var string
     */
    private $twigBaseDir;

    /**
     * input file or directory
     *
     * @var string
     */
    private $profilePath;

    /**
     * @var string
     */
    private $outputPath;

    /**
     * @var Filesystem
     */
    private $fs;

    public function __construct(string $twigBaseDir, string $profilePath, string $outputPath)
    {
        if (substr($twigBaseDir, -1) != '/') {
            $twigBaseDir .= '/';
        }
        $this->twigBaseDir = $twigBaseDir;

        $this->profilePath = $profilePath;
        $this->outputPath = $outputPath;

        $this->fs = new Filesystem();

        $templateLocations = array_filter(
            [
                $this->twigBaseDir,
                $this->twigBaseDir.'base/',
                $this->twigBaseDir.'components/',
                $this->twigBaseDir.'mixins/',
                $this->twigBaseDir.'wrapper/',
                $this->twigBaseDir.'scripts/'
            ],
            function ($item) {
                return $this->fs->exists($item);
            }
        );

        // add our own templates
        $templateLocations[] = __DIR__.'/../resources/templates/';

        $loader = new FilesystemLoader($templateLocations, $this->twigBaseDir);
        $this->twig = new Environment($loader);
        $this->twig->addExtension(new Extension());
        $this->twig->addExtension(new YamlExtension());

    }

    public function renderTwigTemplate($templateName, $templateData) {
        return $this->twig->load($templateName)->render($templateData);
    }

    public function profileIsDirectory() {
        if(is_dir($this->profilePath)) {
            // add trailing slash to be sure..
            if (substr($this->profilePath, -1) != '/') {
                $this->profilePath .= '/';
            }
            return true;
        }

        return false;
    }

    /**
     * returns an array with stuff to transpile, source template key -> value is write destination
     *
     * @return array
     */
    public function getResourcesToTranspile() {
        if (!$this->profileIsDirectory($this->profilePath)) {
            return [$this->profilePath => $this->outputPath];
        }

        $finder = Finder::create()
            ->in($this->profilePath)
            ->files()
            ->ignoreDotFiles(true)
            ->notName('transpiler.yml')
            ->name("*.yml");

        $resources = [];
        foreach ($finder as $file) {
            $resources[$file->getPathname()] = $file->getFilename();
        }

        // is there a transpiler.yml?

        return $resources;
    }

    public function getTranspilerSettings() {
        if (!$this->profileIsDirectory($this->profilePath)) {
            return [];
        }

        $settingsFile = $this->profilePath.'transpiler.yml';
        if ($this->fs->exists($settingsFile)) {
            return Yaml::parse(file_get_contents($settingsFile));
        }

        return [];
    }

    /**
     * @return Environment
     */
    public function getTwig(): Environment
    {
        return $this->twig;
    }

    /**
     * @param Filesystem $fs
     */
    public function setFs(Filesystem $fs): void
    {
        $this->fs = $fs;
    }

    public function writeOutputFile(string $path, string $content)
    {
        if (substr($path, 0, 1) != '/') {
            // no absolute path? compose..
            if (is_file($this->profilePath)) {
                $path = pathinfo($this->outputPath,PATHINFO_DIRNAME).DIRECTORY_SEPARATOR.$path;
            } else {
                // assume directory
                if (substr($this->outputPath, -1) != '/') {
                    $this->outputPath .= '/';
                }
                $path = $this->outputPath.$path;
            }
        }

        $this->fs->dumpFile($path, $content);
    }

    /**
     * @return Filesystem
     */
    public function getFs(): Filesystem
    {
        return $this->fs;
    }

}
