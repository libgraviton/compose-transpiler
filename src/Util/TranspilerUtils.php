<?php
/**
 * utils for transpiler itself and outputprocessors
 */
namespace Graviton\ComposeTranspiler\Util;

use Graviton\ComposeTranspiler\Util\Twig\Extension;
use Symfony\Bridge\Twig\Extension\YamlExtension;
use Symfony\Component\Filesystem\Filesystem;
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
     * @var Filesystem
     */
    private $fs;

    public function __construct(string $twigBaseDir)
    {
        if (substr($twigBaseDir, -1) != '/') {
            $twigBaseDir .= '/';
        }

        $this->fs = new Filesystem();

        $this->twigBaseDir = $twigBaseDir;
        $this->baseTmplDir = 'base/';
        $this->componentDir = 'components/';
        $this->mixinsDir = 'mixins/';
        $this->wrapperDir = 'wrapper/';
        $this->scriptsDir = 'scripts/';

        $templateLocations = array_filter(
            [
                $this->twigBaseDir,
                $this->twigBaseDir.$this->baseTmplDir,
                $this->twigBaseDir.$this->componentDir,
                $this->twigBaseDir.$this->mixinsDir,
                $this->twigBaseDir.$this->wrapperDir,
                $this->twigBaseDir.$this->scriptsDir,
            ],
            function ($item) {
                return $this->fs->exists($item);
            }
        );


        $loader = new FilesystemLoader($templateLocations, $this->twigBaseDir);
        $this->twig = new Environment($loader);
        $this->twig->addExtension(new Extension());
        $this->twig->addExtension(new YamlExtension());

    }

    public function renderTwigTemplate($templateName, $templateData) {
        return $this->twig->load($templateName)->render($templateData);
    }

    /**
     * @return Environment
     */
    public function getTwig(): Environment
    {
        return $this->twig;
    }

    /**
     * @return Filesystem
     */
    public function getFs(): Filesystem
    {
        return $this->fs;
    }

}
