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

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class TranspileCommand extends Command
{

    /**
     * Configures the current command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('compose:transpile')
            ->setDescription('Transpiles docker-compose files from twig templates.')
            ->addArgument(
                'templateDir',
                InputArgument::REQUIRED,
                'Path to the templates dir. Must have components subdirectory.'
            )
            ->addArgument(
                'defFile',
                InputArgument::REQUIRED,
                'Path to the definition (shortened YML) file that needs transpiling.'
            )
            ->addArgument(
                'outFile',
                InputArgument::REQUIRED,
                'Where to write the finished transpiled YML file to.'
            )
            ->addOption(
                'releaseFile',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Path to an optional release file - ${TAG} will be replaced from this file.'
            )
            ->addOption(
                'baseEnvFile',
                'b',
                InputOption::VALUE_OPTIONAL,
                'An optional base .env file that serves as a base for the generated one.'
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
        $templateDir = $input->getArgument('templateDir');
        if (!is_dir($templateDir)) {
            throw new \LogicException('Directory "'.$templateDir.'" does not exist.');
        }

        $defFile = $input->getArgument('defFile');
        if (!file_exists($defFile)) {
            throw new \LogicException('File "'.$defFile.'" does not exist.');
        }

        $t = new \Graviton\ComposeTranspiler\Transpiler($templateDir, $output);

        $releaseFile = $input->getOption('releaseFile');
        if (!is_null($releaseFile)) {
            if (!file_exists($releaseFile)) {
                throw new \LogicException('File "'.$releaseFile.'" does not exist.');
            }
            $t->setReleaseFile($releaseFile);
        }

        $baseEnvFile = $input->getOption('baseEnvFile');
        if (!is_null($baseEnvFile)) {
            if (!file_exists($baseEnvFile)) {
                throw new \LogicException('File "'.$baseEnvFile.'" does not exist.');
            }
            $t->setBaseEnvFile($baseEnvFile);
        }

        $t->transpile($defFile, $input->getArgument('outFile'));
    }
}
