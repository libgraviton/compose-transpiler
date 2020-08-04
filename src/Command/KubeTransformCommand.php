<?php
/**
 * transforms an intermediate "kube" wrapped yml according to several rules
 */
namespace Graviton\ComposeTranspiler\Command;

use Graviton\ComposeTranspiler\KubeTransformer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author   List of contributors <https://github.com/libgraviton/compose-transpiler/graphs/contributors>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://swisscom.ch
 */
class KubeTransformCommand extends Command
{

    /**
     * Configures the current command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('kube:transform')
            ->setDescription('Transforms "intermediate" kube wrapped output files.')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'Path to intermediate file.'
            )
            ->addArgument(
                'outDirectory',
                InputArgument::REQUIRED,
                'Where to write files to'
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
        $file = $input->getArgument('file');
        if (!file_exists($file)) {
            throw new \LogicException('File/Directory "'.$file.'" does not exist.');
        }

        $outDir = $input->getArgument('outDirectory');
        $t = new KubeTransformer($file, $outDir, $output);
        $t->transform();


        return 0;
    }
}
