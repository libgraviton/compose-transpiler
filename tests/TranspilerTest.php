<?php

namespace Graviton\ComposeTranspilerTest;

use Graviton\ComposeTranspiler\Transpiler;
use PHPUnit\Framework\TestCase;
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
        $inflect = false,
        $expectedScripts = []
    ) {
        $sut = new Transpiler(
            __DIR__.'/resources/_templates',
            $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock()
        );

        if (!is_null($releaseFile)) {
            $sut->setReleaseFile($releaseFile);
        }

        if (!is_null($baseEnvFile)) {
            $sut->setBaseEnvFile($baseEnvFile);
        }

        $sut->setInflect($inflect);

        $sut->transpile(__DIR__.'/resources/'.$filename, __DIR__.'/gen.yml');

        $contents = Yaml::parseFile(__DIR__.'/gen.yml');
        $expected = Yaml::parseFile(__DIR__.'/resources/expected/'.$filename);

        foreach ($envFileAsserts as $envFileAssert) {
            $this->assertStringContainsString($envFileAssert, file_get_contents(__DIR__.'/gen.env'));
        }

        $this->assertEquals($expected, $contents);

        // check for scripts if defined. 'key' is generated file, 'value' is what we expect..
        foreach ($expectedScripts as $genScript => $expectedScript) {
            $this->assertFileEquals($expectedScript, $genScript);
            unlink($genScript);
        }

        unlink(__DIR__.'/gen.yml');
        if (!$inflect) unlink(__DIR__.'/gen.env');
    }

    public function dataProvider()
    {
        return [
            [
                "app1.yml",
                __DIR__.'/resources/releaseFile',
                [],
                null,
                false,
                [
                    __DIR__.'/script.sh' => __DIR__.'/resources/expected/scripts/examplescript.sh'
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
                "app5withenv.yml",
                null,
                [
                ],
                __DIR__.'/resources/envFiles/baseEnvInflect.env',
                true
            ]
        ];
    }

}
