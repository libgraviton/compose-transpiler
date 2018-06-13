<?php
/**
 * replacer abstract
 */
namespace Graviton\ComposeTranspiler\Replacer;

use Psr\Log\LoggerInterface;

abstract class ReplacerAbstract {

    protected $logger;

    public function setLogger(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    final public function replaceArray(array $content) {
        $this->init();
        return $this->replaceValuesInArray($content);
    }

    private function replaceValuesInArray(array $content) {
        foreach ($content as $key => $val) {
            if (is_array($val)) {
                $content[$key] = $this->replaceValuesInArray($val);
            } elseif (is_null($val)) {
                $content[$key] = null;
            } else {
                $content[$key] = $this->replace($val);
            }
        }
        return $content;
    }

    abstract protected function init();

    abstract protected function replace($content);
}
