<?php
/**
 * replacer for versions
 */

namespace Graviton\ComposeTranspiler\Replacer;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class VersionTagReplacer extends ReplacerAbstract
{

    private $releaseFile;
    private $patterns = [];
    private $checkString = '${TAG}';

    public function __construct($releaseFile)
    {
        $this->releaseFile = $releaseFile;
    }

    public function init()
    {
        // prepare patterns
        if (!is_null($this->releaseFile)) {
            if (!file_exists($this->releaseFile)) {
                throw new \Exception("File '".$this->releaseFile."' does not exist");
            }

            foreach (file($this->releaseFile) as $release) {
                if (substr(trim($release), 0, 1) == '#') {
                    continue;
                }

                $releaseParts = explode(":", trim($release));

                $pattern = '@('.preg_quote($releaseParts[0]).')\:(\$\{TAG\})@imU';
                // now replace all "\*" (wildcards in release file) with the real wildcard
                $pattern = str_replace('\*', '.*', $pattern);

                $this->patterns[$pattern] = $releaseParts[1];
            }
        }
    }

    /**
     * replaces all ${TAG} variables with the content from the release file
     *
     * @param string $content the compile compose recipe
     * @return mixed replaced
     * @throws \Exception
     */
    public function replace($content)
    {
        if (strpos($content, $this->checkString) === false) {
            return $content;
        }

        foreach ($this->patterns as $pattern => $version) {
            $content = preg_replace($pattern, '$1:'.$version, $content);
        }

        // replace missing ${TAG} mit notice!
        preg_match_all('/([a-z0-9-_]*):\$\{TAG\}/i', $content, $matches);
        foreach ($matches[0] as $matched) {
            $this->logger->warning('Replace unset image '.$matched.' with "latest"!');
            $content = str_replace($matched, str_replace('${TAG}', 'latest', $matched), $content);
        }

        return $content;
    }
}
