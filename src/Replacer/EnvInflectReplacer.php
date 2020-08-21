<?php
/**
 * replacer for envs
 */
namespace Graviton\ComposeTranspiler\Replacer;

use Graviton\ComposeTranspiler\Util\EnvFileHandler;
use Graviton\ComposeTranspiler\Util\Patterns;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class EnvInflectReplacer extends ReplacerAbstract
{

    private $envFile;
    private $envVars;

    public function __construct($envFile)
    {
        $this->envFile = $envFile;

        $envHandler = new EnvFileHandler();
        $this->envVars = $envHandler->interpretEnvFile($envFile);
        if (!is_array($this->envVars)) {
            $this->envVars = [];
        }
    }

    public function init()
    {
        if (!file_exists($this->envFile)) {
            $this->logger->error(
                "Inflect (-i) requested but no base env file (-b) provided -> NOTHING WILL BE REPLACED!"
            );
        }
    }

    /**
     * replaces all ${*} envs with the stuff from the env file or their defaults
     *
     * @param string $content the compile compose recipe
     * @return mixed replaced
     * @throws \Exception
     */
    public function replace($content)
    {
        // get all envs vars
        preg_match_all(Patterns::DOCKER_ENV_VALUES, $content, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return $content;
        }

        $missingVars = [];
        foreach ($matches as $match) {
            $matchParts = explode(':-', $match[1]);

            $varName = $matchParts[0];
            $default = null;
            if (isset($matchParts[1])) {
                $default = $matchParts[1];
            }

            $valueToSet = null;
            if (isset($this->envVars[$varName])) {
                $valueToSet = $this->envVars[$varName];
            } else {
                $valueToSet = $default;
            }

            if (is_null($valueToSet)) {
                $this->logger->warning('No value to set for variable "'.$varName.'" -> removing!');
                $missingVars[$varName] = '';
            }

            $content = str_replace("'".$match[0]."'", $valueToSet, $content);
            $content = str_replace($match[0], $valueToSet, $content);
        }

        if (!empty($missingVars)) {
            $this->logger->warning('List of missing variables: '.implode(', ', array_keys($missingVars)));
        }

        return $content;
    }
}
