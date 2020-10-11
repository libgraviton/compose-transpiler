<?php

namespace Graviton\ComposeTranspilerTest;

use Graviton\ComposeTranspiler\Replacer\VersionTagReplacer;
use Graviton\ComposeTranspiler\Transpiler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class TranspilerTest extends TestCase {

    /**
     *
     * @dataProvider dataProvider
     */
    public function testTranspiling(
        $filename,
        $releaseFile = null,
        $envFileAsserts = [],
        $baseEnvFile = null,
        $expectedScripts = []
    ) {
        $sut = new Transpiler(
            __DIR__.'/resources/_templates',
            __DIR__.'/resources/'.$filename,
            __DIR__.'/generated/gen.yml',
            $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock()
        );

        if (!is_null($releaseFile)) {
            $sut->setReleaseFile($releaseFile);
        }

        if (!is_null($baseEnvFile)) {
            $sut->setBaseEnvFile($baseEnvFile);
        }

        $sut->transpile();

        $contents = Yaml::parseFile(__DIR__.'/generated/gen.yml');
        $expected = Yaml::parseFile(__DIR__.'/resources/expected/'.$filename);

        foreach ($envFileAsserts as $envFileAssert) {
            $this->assertStringContainsString($envFileAssert, file_get_contents(__DIR__.'/generated/gen.env'));
        }

        $this->assertEquals($expected, $contents);

        // check for scripts if defined. 'key' is generated file, 'value' is what we expect..
        foreach ($expectedScripts as $genScript => $expectedScript) {
            $this->assertFileEquals($expectedScript, $genScript);
        }

        (new Filesystem())->remove(__DIR__.'/generated');
    }

    public function dataProvider()
    {
        return [
            [
                "app1.yml",
                __DIR__.'/resources/releaseFile',
                [],
                null,
                [
                    __DIR__.'/generated/script.sh' => __DIR__.'/resources/expected/scripts/examplescript.sh'
                ]
            ],
            [
                "app2.yml",
            ],
            [
                "app3.yml",
            ],
            [
                "app4withenv.yml",
                null,
                [
                    ''
                ]
            ],
            [
                "app4withenv.yml",
                null,
                [
                    'FERDINAND=',
                    'HANS=',
                    'FRED='
                ]
            ],
            [
                "app4withenv.yml",
                null,
                [
                    'FERDINAND=',
                    'HANS=hans',
                    'FRED=fred',
                    'this is a comment'
                ],
                __DIR__.'/resources/envFiles/baseEnv.env'
            ],
            [
                "ymlenv.yml",
                null,
                [
                ]
            ],
            [
                "app6forInstance.yml",
            ]
        ];
    }

    public function testReplacerRawFile()
    {
        $rawFile = __DIR__.'/resources/replacer/fileWithTags.txt';
        $replacer = new VersionTagReplacer(__DIR__.'/resources/releaseFile');
        $logger = new ConsoleLogger($this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock());
        $replacer->setLogger($logger);
        $replacer->init();

        $expected = 'this is some file with nginx:5.5.5 content'.PHP_EOL.PHP_EOL.
                    'another line org/fred-setup:3.0.0'.PHP_EOL;

        $this->assertEquals(
            $expected,
            $replacer->replace(file_get_contents($rawFile))
        );
    }

}
