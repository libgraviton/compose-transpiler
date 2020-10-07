<?php
/**
 * transpile command
 */
namespace Graviton\ComposeTranspiler\Command;

use Graviton\ComposeTranspiler\Transpiler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

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
                'Path to an optional release file - ${TAG} values will be replaced from this file.'
            )
            ->addOption(
                'inflect',
                'i',
                InputOption::VALUE_NONE,
                'If given, values from env file will be replaced in the yml instead of generating an env file'
            )
            ->addOption(
                'regex',
                'x',
                InputArgument::REQUIRED + InputArgument::IS_ARRAY + InputOption::VALUE_IS_ARRAY,
                'Stuff that is replaced (regex) at the end of generating in the yml. Each argument is a '.
                'string like \'[pattern]::[replacer]\''
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
     * @return int exit code
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $templateDir = $input->getArgument('templateDir');
        if (!is_dir($templateDir)) {
            throw new \LogicException('Directory "'.$templateDir.'" does not exist.');
        }

        $defFile = $input->getArgument('defFile');
        if (!file_exists($defFile) && !is_dir($defFile)) {
            throw new \LogicException('File/Directory "'.$defFile.'" does not exist.');
        }

        $t = new Transpiler($templateDir, $output);

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

        // inflect option
        $t->setInflect($input->getOption('inflect'));

        if (is_array($input->getOption('regex'))) {
            $t->setFinalRegexes($input->getOption('regex'));
        }

        // dir or file?
        if (!is_dir($defFile)) {
            $t->transpile($defFile, $input->getArgument('outFile'));
        } else {
            $finder = Finder::create()
                ->in($defFile)
                ->files()
                ->ignoreDotFiles(true)
                ->name("*.yml");

            $outDir = $input->getArgument('outFile');
            if (substr($outDir, -1) != '/') {
                $outDir .= '/';
            }

            // same env file for all yml files
            $t->setEnvFileName($outDir.'dist.env');

            foreach ($finder as $file) {
                $t->transpile($file->getPathname(), $outDir.$file->getFilename());
            }

            // render relese id if given
            if (!is_null($releaseFile)) {
                file_put_contents($outDir.'release-id.release', basename($releaseFile));
            }
        }

        return 0;
    }
}
