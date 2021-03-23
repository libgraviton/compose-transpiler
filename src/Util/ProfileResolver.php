<?php

/**
 * util to resolve profiles with inheritance
 */
namespace Graviton\ComposeTranspiler\Util;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class ProfileResolver {

    private $filename;

    public function __construct($filename)
    {
        $this->filename = $filename;
        $this->fs = new Filesystem();
    }

    public function resolve()
    {
        return $this->resolveInheritance($this->filename);
    }

    private function resolveInheritance($filename, $baseYml = [])
    {
        $yml = Yaml::parseFile($filename);

        if (isset($yml['_inheritance']) && isset($yml['_inheritance']['extends'])) {
            $parentFile = $this->resolveFilePath($filename, $yml['_inheritance']['extends']);

            if (!$this->fs->exists($parentFile)) {
                throw new \LogicException(
                    sprintf(
                        'Parent file "%s" referenced in file "%s" does not exist!',
                        $parentFile,
                        $filename
                    )
                );
            }

            try {
                $parentYml = $this->resolveInheritance($parentFile, $yml);
            } catch (\Exception $e) {
                throw new \RuntimeException('Could not resolve parent in '.$filename.' (' . $parentFile . ')', 0, $e);
            }

            if (isset($yml['_inheritance']['unsets']) && is_array($yml['_inheritance']['unsets'])) {
                foreach ($yml['_inheritance']['unsets'] as $unsetter) {
                    $parentYml = $this->unsetInArray($parentYml, $unsetter);
                }
            }

            unset($parentYml['_inheritance']);

            $yml = $parentYml;
        }

        $yml = \Ckr\Util\ArrayMerger::doMerge($yml, $baseYml);

        return $yml;
    }

    private function resolveFilePath($fromFile, $toFile)
    {
        if ($this->fs->isAbsolutePath($toFile)) {
            return $toFile;
        }

        return dirname($fromFile).DIRECTORY_SEPARATOR.$toFile;
    }

    private function unsetInArray($arr, $path)
    {
        $parts = explode('.', $path);
        $lastPart = array_pop($parts);
        $setKey = null;
        foreach ($parts as $part) {
            if (is_null($setKey)) {
                $setKey = &$arr[$part];
            } else {
                $setKey = &$setKey[$part];
            }
        }

        // can we unset?
        if (!is_null($setKey) && is_array($setKey) && array_key_exists($lastPart, $setKey)) {
            unset($setKey[$lastPart]);
        }

        return $arr;
    }

}
