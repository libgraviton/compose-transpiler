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
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(TranspilerUtils $utils)
    {
        $this->utils = $utils;
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
}
