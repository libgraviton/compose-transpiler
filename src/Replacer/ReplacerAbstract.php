<?php
/**
 * replacer abstract
 */
namespace Graviton\ComposeTranspiler\Replacer;

use Neunerlei\Arrays\Arrays;
use Psr\Log\LoggerInterface;

abstract class ReplacerAbstract {

    protected $logger;

    public function setLogger(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    final public function replaceArray(array $content) {
        $this->init();
        return Arrays::mapRecursive($content, \Closure::fromCallable([$this, 'replace']));
    }

    abstract protected function init();

    abstract public function replace($content);
}
