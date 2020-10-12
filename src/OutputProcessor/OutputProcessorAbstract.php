<?php
/**
 * OutputProcessor abstract
 */
namespace Graviton\ComposeTranspiler\OutputProcessor;

use Graviton\ComposeTranspiler\Transpiler;
use Graviton\ComposeTranspiler\Util\TranspilerUtils;
use Psr\Log\LoggerInterface;

abstract class OutputProcessorAbstract {

    /**
     * @var Transpiler
     */
    protected $transpiler;

    /**
     * @var TranspilerUtils
     */
    protected $utils;

    /**
     * @var array the content of the raw profile before anything
     */
    protected $profile;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(TranspilerUtils $utils, array $profile)
    {
        $this->utils = $utils;
        $this->profile = $profile;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function log(string $message) {
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->info($message);
        }
    }

    abstract function processFile(Transpiler $transpiler, string $filePath, array $fileContent);

    public function addExposeServices() : bool {
        return true;
    }

    protected function getOutputOptions() {
        $settings = $this->utils->getTranspilerSettings();
        if (isset($settings['outputProcessor']['options'])) {
            return $settings['outputProcessor']['options'];
        }
        return [];
    }

    /**
     * called at the beginning..
     */
    public function startup() {
    }

    /**
     * called at the end..
     */
    public function finalize() {
    }
}
