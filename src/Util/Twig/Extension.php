<?php
/**
 * twig extensions
 */

namespace Graviton\ComposeTranspiler\Util\Twig;

use Graviton\ComposeTranspiler\Util\YamlUtils;
use Rs\Json\Pointer;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
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
            new TwigFilter('jsonEnc', [$this, 'jsonEnc']),
            new TwigFilter('jsonEnv', [$this, 'jsonEnv']),
            new TwigFilter('ensureBoolean', [$this, 'ensureBoolean'])
        ];
    }

    public function getFunctions()
    {
        return [
            new TwigFunction('strRepeat', 'str_repeat'),
            new TwigFunction('ensureBoolean', [$this, 'ensureBoolean']),
            new TwigFunction(
                'subPathRendering',
                [$this, 'subPathRendering'],
                [
                    'needs_environment' => true,
                    'needs_context' => true
                ]
            )
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

    public function yamlEnc($structure, $indent = 2)
    {
        $dumper = new Dumper(2);
        $yml = $dumper->dump($structure, 500, $indent);
        return $yml;
    }

    public function jsonEnc($structure)
    {
        return json_encode($structure);
    }

    public function jsonEnv($structure)
    {
        return str_replace('"', '\\"', json_encode($structure));
    }

    public function ensureBoolean($value) {
        if (is_bool($value) && $value == true) {
            return 'true';
        }
        if (is_bool($value) && $value == false) {
            return 'false';
        }
        if ($value == '1' || $value == 'true') {
            return 'true';
        }
        return 'false';
    }

    /**
     * @throws Pointer\InvalidJsonException
     * @throws \Twig\Error\SyntaxError
     * @throws Pointer\NonWalkableJsonException
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\LoaderError
     */
    public function subPathRendering(Environment $env, $context, $filename, $subPath = null, $indent = 2) {
        $content = $env->render($filename, $context);

        // only select first!
        $array = YamlUtils::multiParse($content);
        if (isset($array[0]) && is_array($array[0])) {
            $array = $array[0];
        }

        if (!is_null($subPath)) {
            $jsonPointer = new Pointer(json_encode($array));
            $array = $jsonPointer->get($subPath);

            // back to array
            $array = json_decode(
                json_encode($array),
                true
            );
        }

        return $this->yamlEnc($array, $indent);
    }
}
