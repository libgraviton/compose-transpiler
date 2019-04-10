<?php
/**
 * transpile command
 */
namespace Graviton\ComposeTranspiler\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class VerifyImagesCommand extends Command
{

    /**
     * Configures the current command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('compose:verifyimages')
            ->setDescription('Verifies that all images mentioned in a directory exist.')
            ->addArgument(
                'dir',
                InputArgument::REQUIRED,
                'Dir to generated files'
            );
    }

    /**
     * Executes the current command.
     *
     * @param InputInterface  $input  User input on console
     * @param OutputInterface $output Output of the command
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fs = Finder::create()
            ->files()
            ->in($input->getArgument('dir'))
            ->name('*.yml');

        foreach ($fs as $file) {
            $filename = $file->getPathname();
            $output->writeln("Parsing file '${filename}'");
            $this->parseFile($filename, $output);
        }
    }

    private function parseFile($filename, OutputInterface $output)
    {
        $images = [];
        $content = Yaml::parseFile($filename);
        if (is_array($content) && isset($content['services'])) {
            foreach ($content['services'] as $service) {
                if (isset($service['image'])) {
                    $images[] = $service['image'];
                }
            }
        }

        var_dump($images);
    }

    private function checkImage($imageName)
    {
// https://hackernoon.com/inspecting-docker-images-without-pulling-them-4de53d34a604

        /*
        $client = \Http\Discovery\HttpClientDiscovery::find();
        $requestFactory = \Http\Discovery\Psr17FactoryDiscovery::findRequestFactory();

        $image = 'comp/mongodb';
        $tag = 'v4.01';

        $request = $requestFactory->createRequest('GET', 'https://registryUri/v2/'.$image.'/manifests/'.$tag);

        $request = $request->withHeader('Accept', 'application/vnd.docker.distribution.manifest.v2+json')
            ->withHeader('Authorization', 'Basic ');

        $resp = $client->sendRequest($request);

        var_dump($resp->getBody()->getContents());

        var_dump($resp); die;

        var_dump($requestFactory); die;
        */
    }
}
