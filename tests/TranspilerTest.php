<?php

class TranspilerTest extends \PHPUnit\Framework\TestCase {

    /**
     *
     * @dataProvider dataProvider
     */
    public function testTranspiling($filename, $releaseFile = null)
    {
        $sut = new \Graviton\ComposeTranspiler\Transpiler(
            __DIR__.'/resources/_templates'
        );

        if (!is_null($releaseFile)) {
            $sut->setReleaseFile($releaseFile);
        }

        $sut->transpile(__DIR__.'/resources/'.$filename, __DIR__.'/gen.yml');

        $contents = \Symfony\Component\Yaml\Yaml::parseFile(__DIR__.'/gen.yml');
        $expected = \Symfony\Component\Yaml\Yaml::parseFile(__DIR__.'/resources/expected/'.$filename);

        $this->assertEquals($expected, $contents);
    }

    public function dataProvider()
    {
        return [
            [
                "app1.yml",
                __DIR__.'/resources/releaseFile'
            ],
            [
                "app2.yml",
            ]
        ];
    }

}
