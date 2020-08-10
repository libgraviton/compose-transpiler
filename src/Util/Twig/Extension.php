<?php
/**
 * twig extensions
 */

namespace Graviton\ComposeTranspiler\Util\Twig;

use Symfony\Component\Yaml\Dumper;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class Extension extends AbstractExtension
{
    public function getFilters()
    {
        return [
            new TwigFilter('yamlEnv', [$this, 'yamlEnv']),
            new TwigFilter('yamlEnc', [$this, 'yamlEnc']),
        ];
    }

    public function getFunctions()
    {
        return [
            new TwigFunction('strRepeat', 'str_repeat')
        ];
    }

    /**
     * converts a structure (like a big array) into an correctly encoded yml ENV value for symfony env parsers
     *
     * @param mixed $structure structure
     *
     * @return string encoded env
     */
    public function yamlEnv($structure)
    {
        $dumper = new Dumper(2);
        $yml = $dumper->dump($structure, 0);
        return $yml;
    }

    public function yamlEnc($structure)
    {
        $dumper = new Dumper(2);
        $yml = $dumper->dump($structure, 500);
        return $yml;
    }
}