<?php
/**
 * yaml utils
 */
namespace Graviton\ComposeTranspiler\Util;

use Symfony\Component\Yaml\Yaml;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class YamlUtils
{

    public static $glue = PHP_EOL."---".PHP_EOL;

    public static function multiParse($filename) {
        $content = file_get_contents($filename);

        // split by '---\n'
        $parts = preg_split('/---\n/m', $content);
        $returnParts = [];

        foreach ($parts as $part) {
            $part = Yaml::parse($part);
            if (empty($part)) {
                continue;
            }
            $returnParts[] = $part;
        }

        return $returnParts;
    }

    public static function multiDump(array $parts) {
        $singleOnes = [];
        foreach ($parts as $part) {
            if (empty($part)) {
                continue;
            }
            $singleOnes[] = self::dump($part);
        }
        return self::$glue.implode(self::$glue, $singleOnes);
    }

    public static function dump($content) {
        return Yaml::dump($content, 99, 2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK +
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE +
            Yaml::DUMP_OBJECT_AS_MAP
        );
    }

}