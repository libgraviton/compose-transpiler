<?php
/**
 * verify image existence command
 */
namespace Graviton\ComposeTranspiler\Command;

use Graviton\ComposeTranspiler\Util\RegistryRequestFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
     * @var RegistryRequestFactory
     */
    private $registryRequestFactory;

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
            ->addOption(
                'credentialFile',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to registry auth file'
            )
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
        $this->registryRequestFactory = new RegistryRequestFactory($input->getOption('credentialFile'));

        $fs = Finder::create()
            ->files()
            ->in($input->getArgument('dir'))
            ->name('*.yml');

        $images = [];
        foreach ($fs as $file) {
            $filename = $file->getPathname();
            $output->writeln("Parsing file '${filename}'");

            $images = array_merge(
                $this->parseFile($filename, $output),
                $images
            );
        }

        $images = array_unique($images);
        asort($images);

        foreach ($images as $image) {
            $output->write("Checking image '${image}'... ");

            switch ($this->checkImage($image)) {
                case null:
                    $output->write('SKIPPED, CANNOT PARSE!', true);
                    break;
                case true:
                    $output->write('OK!', true);
                    break;
                case false:
                    $output->write('DOES NOT EXIST!', true);
                    exit(-1);
            }
        }
    }

    private function parseFile($filename)
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

        return $images;
    }

    private function checkImage($imageName)
    {
        $client = $this->registryRequestFactory->getHttpClient();

        $request = $this->registryRequestFactory->getVerifyImageRequest($imageName);
        if ($request === null) {
            return null;
        }

        $resp = $client->sendRequest($request);

        if ($resp->getStatusCode() == 200) {
            return true;
        }

        return false;
    }
}
