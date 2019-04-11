<?php
/**
 * composes requests for docker registry v2
 */

namespace Graviton\ComposeTranspiler\Util;

use Graviton\ComposeTranspiler\Util\HttpClient\DockerAuthPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\HttpClient;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class RegistryRequestFactory
{

    /**
     * @var HttpClient
     */
    private $client;

    /**
     * @var RequestFactoryInterface
     */
    private $requestFactory;

    public function __construct($credentialFile = null)
    {
        $this->client = new PluginClient(
            HttpClientDiscovery::find(),
            [
                new DockerAuthPlugin($credentialFile)
            ]
        );
        $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
    }

    /**
     * @return PluginClient client
     */
    public function getHttpClient()
    {
        return $this->client;
    }

    public function getVerifyImageRequest($imageName)
    {
        $info = $this->getImageNameParts($imageName);

        if (!isset($info['image']) || $info['image'] == false) {
            return null;
        }

        $uri = new Uri();
        $uri = $uri
            ->withScheme('https')
            ->withHost($info['registry'])
            ->withPath('/v2/'.$info['image'].'/manifests/'.$info['label']);

        return $this->wrapInManifestHeader(
            $this->requestFactory->createRequest('GET', $uri)
        );
    }

    private function wrapInManifestHeader(RequestInterface $request)
    {
        return $request
            ->withHeader('Accept', 'application/vnd.docker.distribution.manifest.v2+json');
    }

    private function getImageNameParts($imageName)
    {
        $info = [];
        $labelParts = explode(':', $imageName);
        $info['label'] = array_pop($labelParts);

        $uri = new Uri('http://'.implode('', $labelParts));
        $info['registry'] = $uri->getHost();

        $info['image'] = substr($uri->getPath(), 1);

        return $info;
    }
}
