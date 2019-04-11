<?php
/**
 * plugin for registry auth via ~/.docker/config.json
 */

namespace Graviton\ComposeTranspiler\Util\HttpClient;

use Http\Client\Common\Plugin;
use Http\Promise\Promise;
use Psr\Http\Message\RequestInterface;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class DockerAuthPlugin implements Plugin
{

    /**
     * @var array
     */
    private $authFile;

    public function __construct($authFile = null)
    {
        if (null !== $authFile) {
            $this->authFile = json_decode(file_get_contents($authFile), true);
        }
    }

    /**
     * Handle the request and return the response coming from the next callable.
     *
     * @see http://docs.php-http.org/en/latest/plugins/build-your-own.html
     *
     * @param callable $next Next middleware in the chain, the request is passed as the first argument
     * @param callable $first First middleware in the chain, used to to restart a request
     *
     * @return Promise Resolves a PSR-7 Response or fails with an Http\Client\Exception (The same as HttpAsyncClient)
     */
    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        $host = $request->getUri()->getHost();

        if (is_array($this->authFile) && isset($this->authFile['auths']) && isset($this->authFile['auths'][$host])) {
            $hostAuth = $this->authFile['auths'][$host];
            if (isset($hostAuth['auth'])) {
                $request = $request->withHeader('Authorization', 'Basic '.$hostAuth['auth']);
            }
        }

        return $next($request);
    }
}
