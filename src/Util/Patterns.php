<?php
/**
 * just some regex patterns
 */
namespace Graviton\ComposeTranspiler\Util;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class Patterns
{
    /**
     * selects all ${VARNAME:-default} strings
     */
    public const DOCKER_ENV_VALUES = '/\$\{([[:word:][:print:]\:\-\_]*)\}/iU';
}
