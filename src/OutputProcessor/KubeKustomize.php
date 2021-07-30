<?php
/**
 * KubeKustomize outputprocessor
 */
namespace Graviton\ComposeTranspiler\OutputProcessor;

use Graviton\ComposeTranspiler\Transpiler;
use Graviton\ComposeTranspiler\Util\EnvFileHandler;
use Graviton\ComposeTranspiler\Util\Patterns;
use Graviton\ComposeTranspiler\Util\YamlUtils;
use Neunerlei\Arrays\Arrays;

class KubeKustomize extends OutputProcessorAbstract {

    private $outputOptions = [];

    private $configMap = [];
    private $resources = [];
    private $secretEnvs = [];
    private $projectName = 'projectName';

    // stuff that goes into kustomization.yaml
    private $jsonPatches = [];
    private $configurations = [];

    // these fields in the *twig base template* are global to the service (meaning deployment in K8 terms), not the container
    private $serviceMetaFields = [
        '_servicePorts',
        '_secretEnvs',
        '_exposes',
        'volumes'
    ];

    function processFile(Transpiler $transpiler, string $filePath, array $fileContent, array $profile) {
        if (isset($this->outputOptions['projectName'])) {
            $this->projectName = $this->outputOptions['projectName'];
        }

        // make fundamental alterations here.. like merging containers together in a pod..
        $fileContent = $this->structureAlterations($fileContent, $profile);

        $twigVars = array_merge(
            $this->outputOptions,
            $fileContent
        );

        // render to kube yaml
        $kubeYaml = $this->utils->renderTwigTemplate('kube.twig', $twigVars);

        // transform secret/env refs
        try {
            $parts = YamlUtils::multiParse($kubeYaml);
        } catch (\Exception $e) {
            throw new \Exception('Error parsing: '.var_export($kubeYaml, true));
        }
        $transformed = [];

        $callable = \Closure::fromCallable([$this, 'traverseArray']);

        // the first job is to traverse all this and see for ${} matches..
        foreach ($parts as $part) {
            $transformed[] = Arrays::mapRecursive($part, $callable);
        }

        $yaml = YamlUtils::multiDump($transformed);

        $this->utils->writeOutputFile($filePath, $yaml);
        $this->logger->info('Wrote file "' . $filePath . '"');

        // do we have any env file?
        if ($transpiler->getBaseEnvFile()) {
            $envFileHandler = new EnvFileHandler($this->utils);
            $vars = $envFileHandler->interpretEnvFile($transpiler->getBaseEnvFile());
            foreach ($vars as $varName => $varValue) {
                if (is_string($varName) && $varName != '_') {
                    $this->configMap[$varName] = $varValue;
                }
            }
        }

        $this->resources[] = basename($filePath);
    }

    public function startup()
    {
        $this->outputOptions = $this->getOutputOptions();
    }

    public function finalize()
    {
        // write kustomization.yaml
        ksort($this->configMap);
        ksort($this->secretEnvs);

        // do we have any json patches referenced?
        if (isset($this->outputOptions['patchesJson6902'])) {
            foreach ($this->outputOptions['patchesJson6902'] as $patch) {
                if (!isset($patch['template'])) {
                    $this->logger->warning('Patch element has no "template" set, skipping');
                    continue;
                }

                $template = $patch['template'];
                unset($patch['template']);

                $templateParams = [];
                if (isset($patch['templateParams'])) {
                    $templateParams = $patch['templateParams'];
                    unset($patch['templateParams']);
                }

                $this->jsonPatches[] = $patch;

                $output = $this->utils->renderTwigTemplate($template, $templateParams);

                // pretty print..
                $output = json_encode(
                    json_decode($output),
                    JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES
                );


                // render template
                $this->utils->writeOutputFile(
                    $patch['path'],
                    $output
                );
                $this->logger->info('Wrote file "'.$template.'"');
            }
        }
        // any kustomize configurations?
        if (isset($this->outputOptions['configurations'])) {
            foreach ($this->outputOptions['configurations'] as $configuration) {
                $this->utils->writeOutputFile(
                    $configuration,
                    $this->utils->renderTwigTemplate($configuration, [])
                );
                $this->configurations[] = $configuration;
            }
        }

        // write kustomization.yaml
        $this->utils->writeOutputFile(
            'kustomization.yaml',
            YamlUtils::dump($this->getKustomizationYaml())
        );

        // write list of secret envs
        if (count($this->secretEnvs) > 0) {
            ksort($this->secretEnvs);
            $this->utils->writeOutputFile(
                'secretenvs.yaml',
                YamlUtils::dump(array_keys($this->secretEnvs))
            );
        }
    }

    public function addExposeServices() : bool {
        return false;
    }

    /**
     * handles the transformation of each element in the array
     *
     * @param $currentValue
     * @param $currentKey
     * @param $pathOfKeys
     * @param $inputArray
     * @return mixed
     */
    private function traverseArray($currentValue, $currentKey, $pathOfKeys, $inputArray) {
        // the full path
        $path = implode('.', $pathOfKeys);

        // memorize component
        if ($path == 'metadata.name') {
            $this->currentComponent = $currentValue;
        }

        // any replacers in the image name?
        if ($currentKey == 'image' || $currentKey == 'name') {
            if (isset($this->outputOptions['imageNameReplaces'])) {
                foreach ($this->outputOptions['imageNameReplaces'] as $replace) {
                    $currentValue = str_replace(
                        $replace['search'],
                        $replace['replace'],
                        $currentValue
                    );
                }
            }
        }

        // match ${VARNAME} stuff
        preg_match_all(Patterns::DOCKER_ENV_VALUES, $currentValue, $matches, PREG_SET_ORDER);
        if (empty($matches)) {
            // no changes
            return $currentValue;
        }

        $isEnvOnlyValueContext = (
            strpos($path, '.env.') !== false && strlen($path) > 10 && substr($path, -10) == '.valueFrom'
        );

        $secretPrefix = '[SECRET]';
        $isSecretEnv = (
            strlen($currentValue) > strlen($secretPrefix) && substr($currentValue, 0, strlen($secretPrefix)) == $secretPrefix
        );
        if ($isSecretEnv) {
            // remove prefix again
            $currentValue = substr($currentValue, strlen($secretPrefix));
        }

        foreach ($matches as $match) {
            $matchParts = explode(':-', $match[1]);
            $varName = trim($matchParts[0]);
            $default = null;
            if (isset($matchParts[1])) {
                $default = trim($matchParts[1]);
            }

            // make sure it exists
            if (!$isSecretEnv) {
                if (!array_key_exists($varName, $this->configMap)) {
                    $this->configMap[$varName] = '';
                }
                if (!empty($default)) {
                    $this->configMap[$varName] = $default;
                }
            } else {
                $this->secretEnvs[$varName] = '';
            }

            // is the current value the whole string? if so, replace with ConfigMap reference!
            if ($isEnvOnlyValueContext) {
                if (!$isSecretEnv) {
                    $currentValue = [
                        'configMapKeyRef' => [
                            'name' => $this->projectName,
                            'key' => $varName
                        ]
                    ];
                } else {
                    $currentValue = [
                        'secretKeyRef' => [
                            'name' => $this->projectName,
                            'key' => $varName
                        ]
                    ];
                }
            } else {
                // change braces
                $currentValue = str_replace($match[0], '$('.$varName.')', $currentValue);
            }
        }

        return $currentValue;
    }

    private function getKustomizationYaml() {
        $yaml = [
            'apiVersion' => 'kustomize.config.k8s.io/v1beta1',
            'kind' => 'Kustomization'
        ];

        $yaml['resources'] = $this->resources;

        // patches?
        if (!empty($this->jsonPatches)) {
            $yaml['patchesJson6902'] = $this->jsonPatches;
        }

        // configurations?
        if (!empty($this->configurations)) {
            $yaml['configurations'] = $this->configurations;
        }

        // configmap
        $mapEntries = [];
        foreach ($this->configMap as $name => $value) {
            $entry = $name.'=';
            if (!empty($value)) {
                $entry .= $value;
            }
            $mapEntries[] = $entry;
        }

        $yaml['configMapGenerator'] = [];
        $yaml['configMapGenerator'][] = [
            'name' => $this->projectName,
            'literals' => $mapEntries
        ];

        // expose everything as VARs as well...
        $yaml['vars'] = [];
        foreach ($this->configMap as $name => $value) {
            $yaml['vars'][] = [
                'name' => $name,
                'objref' => [
                    'apiVersion' => 'v1',
                    'kind' => 'ConfigMap',
                    'name' => $this->projectName
                ],
                'fieldref' => [
                    'fieldpath' => 'data.'.$name
                ]
            ];
        }

        return $yaml;
    }

    /**
     * here some magic happens (perhaps dark), where we ensure a bit a different structure *for the twig templates*
     * that allows us to be more complex there - like rendering multiple compose services inside 1 pod..
     *
     * @param array $structure
     * @param array $profile
     * @return array
     */
    private function structureAlterations(array $structure, array $profile) {

        if (isset($structure['services'])) {
            // first, duplicate stuff into global that are containers for the global level
            // **** STUFF IN CONTAINERS THAT NEED TO GO OUTSIDE OF THAT SCOPE HAS BE COPIED HERE!
            foreach ($structure['services'] as $name => &$innerService) {
                $innerService['containers'] = [array_merge($innerService, ['name' => $name])];

                $innerService['volumes'] = [];
                if (isset($innerService['_volumes'])) {
                    foreach ($innerService['_volumes'] as $innerVolume) {
                        $innerService['volumes'][] = ['serviceName' => $name, 'name' => $innerVolume];
                    }
                }

                // is there a replica property defined in the profile?
                if (isset($profile['components'][$name]['replicas'])) {
                    $innerService['replicas'] = $profile['components'][$name]['replicas'];
                }
            }

            // do we need to merge containers together as pods? drop those that are merged..
            $services = [];
            foreach ($structure['services'] as $name => $service) {
                // already defined in target array?
                if (isset($services[$name])) {
                    continue;
                }

                // is there a merge defined?
                if (isset($profile['components'][$name]['mergeIntoComponentPod'])) {
                    $mergeIntoName = $profile['components'][$name]['mergeIntoComponentPod'];
                    if (!isset($structure['services'][$mergeIntoName])) {
                        throw new \RuntimeException(
                            "Service ".$name." wants to be merge into ".$mergeIntoName.", but this doesn't exist!"
                        );
                    }

                    // merge into target
                    $targetService = $structure['services'][$mergeIntoName];
                    // merge global arguments
                    foreach ($this->serviceMetaFields as $fieldName) {
                        if (!isset($service[$fieldName])) {
                            continue;
                        }
                        if (!isset($targetService[$fieldName])) {
                            $targetService[$fieldName] = [];
                        }
                        $targetService[$fieldName] = array_merge($targetService[$fieldName], $service[$fieldName]);
                    }
                    // add to target containers
                    $targetService['containers'][] = array_merge($service, ['name' => $name]);

                    // add to global services
                    $services[$mergeIntoName] = $targetService;

                    // skip now as we don't want to add this as single service
                    continue;
                }

                $services[$name] = $service;
            }
            $structure['services'] = $services;
        }

        return $structure;
    }
}
