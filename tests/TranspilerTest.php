<?php

namespace Graviton\ComposeTranspilerTest;

use Graviton\ComposeTranspiler\Replacer\VersionTagReplacer;
use Graviton\ComposeTranspiler\Transpiler;
use Graviton\ComposeTranspiler\Util\YamlUtils;
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

    public function testComposeDirTranspiling()
    {
        $sut = new Transpiler(
            __DIR__.'/resources/_templates',
            __DIR__.'/resources/composeprofile',
            __DIR__.'/generated/',
            $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock()
        );
        $sut->setBaseEnvFile(__DIR__.'/resources/composeprofile/compose.env');

        $sut->transpile();

        $this->assertEqualsCanonicalizing(
            Yaml::parseFile(__DIR__.'/resources/expected/composeprofile/compose.yml'),
            Yaml::parseFile(__DIR__.'/generated/compose.yml')
        );

        $this->assertEqualsCanonicalizing(
            Yaml::parseFile(__DIR__.'/resources/expected/composeprofile/compose2.yml'),
            Yaml::parseFile(__DIR__.'/generated/compose2.yml')
        );

        // should have 5 lines, we assume then that the content is fine
        $this->assertEquals(5, count(file(__DIR__.'/generated/dist.env')));

        (new Filesystem())->remove(__DIR__.'/generated');
    }

    /**
     * here, we transpile the 'kubeprofile' directory and check the generated folder..
     * it uses the kube-kustomize outputcontroller and specifies settings in the folder in transpiler.yml
     */
    public function testKubeDirTranspiling()
    {
        $sut = new Transpiler(
            __DIR__.'/resources/_templates',
            __DIR__.'/resources/kubeprofile',
            __DIR__.'/generated/',
            $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock()
        );
        $sut->setBaseEnvFile(__DIR__.'/resources/kubeprofile/kube.env');
        $sut->setReleaseFile(__DIR__.'/resources/kubeprofile/kube.release');

        $sut->transpile();

        // kube.yml and kube2.yml should be identical, same as expected one..
        $expectedKubeYaml = YamlUtils::multiParse(__DIR__.'/resources/expected/kubeconfig/kube.yml');

        // kube.yml should be as expected
        $this->assertEqualsCanonicalizing(
            $expectedKubeYaml,
            YamlUtils::multiParse(__DIR__.'/generated/kube.yml')
        );
        // kube2.yml should be identical
        $this->assertEqualsCanonicalizing(
            $expectedKubeYaml,
            YamlUtils::multiParse(__DIR__.'/generated/kube2.yml')
        );

        // see kustomization.yaml is as expected
        $this->assertEqualsCanonicalizing(
            Yaml::parseFile(__DIR__.'/resources/expected/kubeconfig/kustomization.yaml'),
            Yaml::parseFile(__DIR__.'/generated/kustomization.yaml')
        );

        // verify files that were copied are the same
        $this->assertFileEquals(
            __DIR__.'/resources/_templates/kustomize_configs/type1.yaml',
            __DIR__.'/generated/kustomize_configs/type1.yaml',
        );
        $this->assertFileEquals(
            __DIR__.'/resources/_templates/kustomize_configs/type2.yaml',
            __DIR__.'/generated/kustomize_configs/type2.yaml',
        );
        $this->assertFileEquals(
            __DIR__.'/resources/_templates/kustomize_patches/added-env.json',
            __DIR__ . '/generated/patches/added-env-patch.json',
        );

        (new Filesystem())->remove(__DIR__.'/generated');
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
