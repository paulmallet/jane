<?php

namespace Joli\Jane;

use Joli\Jane\Generator\Context\Context;
use Joli\Jane\Generator\ModelGenerator;
use Joli\Jane\Generator\Naming;
use Joli\Jane\Generator\NormalizerGenerator;
use Joli\Jane\Guesser\ChainGuesser;
use Joli\Jane\Guesser\JsonSchema\JsonSchemaGuesserFactory;
use Joli\Jane\Normalizer\NormalizerFactory;
use Joli\Jane\Runtime\Encoder\RawEncoder;
use PhpCsFixer\Cache\NullCacheManager;
use PhpCsFixer\Differ\NullDiffer;
use PhpCsFixer\Error\ErrorsManager;
use PhpCsFixer\Linter\Linter;
use PhpCsFixer\Runner\Runner;
use PhpParser\PrettyPrinter\Standard;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;
use PhpCsFixer\Config;
use PhpCsFixer\ConfigInterface;
use PhpCsFixer\Console\ConfigurationResolver;
use PhpCsFixer\Finder;

class Jane
{
    const VERSION = '1.0-dev';

    private $serializer;

    private $modelGenerator;

    private $normalizerGenerator;

    private $fixerConfig;

    private $chainGuesser;

    public function __construct(Serializer $serializer, ChainGuesser $chainGuesser, ModelGenerator $modelGenerator, NormalizerGenerator $normalizerGenerator, ConfigInterface $fixerConfig = null)
    {
        $this->serializer = $serializer;
        $this->chainGuesser = $chainGuesser;
        $this->modelGenerator = $modelGenerator;
        $this->normalizerGenerator = $normalizerGenerator;
        $this->fixerConfig = $fixerConfig;
    }

    public function setFixerConfig(ConfigInterface $fixerConfig)
    {
        $this->fixerConfig = $fixerConfig;
    }

    /**
     * Return a list of class guessed.
     *
     * @param $registry
     *
     * @return Context
     */
    public function createContext(Registry $registry)
    {
        // List of schemas can evolve, but we don't want to generate new schema dynamically added, so we "clone" the array
        // to have a fixed list of schemas
        $schemas = array_values($registry->getSchemas());

        /** @var Schema $schema */
        foreach ($schemas as $schema) {
            $jsonSchema = $this->serializer->deserialize(file_get_contents($schema->getOrigin()), 'Joli\Jane\Model\JsonSchema', 'json', [
                'document-origin' => $schema->getOrigin()
            ]);

            $this->chainGuesser->guessClass($jsonSchema, $schema->getRootName(), $schema->getOrigin() . '#', $registry);
        }

        foreach ($registry->getSchemas() as $schema) {
            foreach ($schema->getClasses() as $class) {
                $properties = $this->chainGuesser->guessProperties($class->getObject(), $schema->getRootName(), $registry);

                foreach ($properties as $property) {
                    $property->setType($this->chainGuesser->guessType($property->getObject(), $property->getName(), $registry, $schema));
                }

                $class->setProperties($properties);
            }
        }


        return new Context($registry);
    }

    /**
     * Generate code.
     *
     * @param Registry $registry
     *
     * @return array
     */
    public function generate($registry)
    {
        $context = $this->createContext($registry);

        $prettyPrinter = new Standard();
        $modelFiles = [];
        $normalizerFiles = [];

        foreach ($registry->getSchemas() as $schema) {
            if (!file_exists(($schema->getDirectory().DIRECTORY_SEPARATOR.'Model'))) {
                mkdir($schema->getDirectory().DIRECTORY_SEPARATOR.'Model', 0755, true);
            }

            if (!file_exists(($schema->getDirectory().DIRECTORY_SEPARATOR.'Normalizer'))) {
                mkdir($schema->getDirectory().DIRECTORY_SEPARATOR.'Normalizer', 0755, true);
            }

            $context->setCurrentSchema($schema);

            $modelFiles = array_merge($modelFiles, $this->modelGenerator->generate($schema, $schema->getRootName(), $context));
            $normalizerFiles = array_merge($normalizerFiles, $this->normalizerGenerator->generate($schema, $schema->getRootName(), $context));
        }

        $generated = [];

        foreach ($modelFiles as $file) {
            $generated[] = $file->getFilename();
            file_put_contents($file->getFilename(), $prettyPrinter->prettyPrintFile([$file->getNode()]));
        }

        foreach ($normalizerFiles as $file) {
            $generated[] = $file->getFilename();
            file_put_contents($file->getFilename(), $prettyPrinter->prettyPrintFile([$file->getNode()]));
        }

        foreach ($registry->getSchemas() as $schema) {
            $this->fix($schema->getDirectory());
        }

        return $generated;
    }

    /**
     * Fix files generated in a directory.
     *
     * @param $directory
     *
     * @return array|void
     */
    protected function fix($directory)
    {
        if (!class_exists('PhpCsFixer\Runner\Runner')) {
            return;
        }

        /** @var Config $fixerConfig */
        $fixerConfig = $this->fixerConfig;

        if (null === $fixerConfig) {
            $fixerConfig = Config::create()
                ->setRiskyAllowed(true)
                ->setRules(
                    array(
                        '@Symfony' => true,
                        'array_syntax' => array('syntax' => 'short'),
                        'simplified_null_return' => false,
                        'ordered_imports' => true,
                        'phpdoc_order' => true,
                        'binary_operator_spaces' => array('align_equals'=>true),
                        'concat_space' => false
                    )
                );
        }
        $resolverOptions = array('allow-risky' => true);
        $resolver = new ConfigurationResolver($fixerConfig, $resolverOptions, $directory);

        $finder = new Finder();
        $finder->in($directory);
        $fixerConfig->setFinder($finder);

        $runner = new Runner(
            $resolver->getConfig()->getFinder(),
            $resolver->getFixers(),
            new NullDiffer(),
            null,
            new ErrorsManager(),
            new Linter(),
            false,
            new NullCacheManager()
        );

        return $runner->fix();
    }

    public static function build($options = [])
    {
        $serializer = self::buildSerializer();
        $chainGuesser = JsonSchemaGuesserFactory::create($serializer, $options);
        $naming = new Naming();
        $modelGenerator = new ModelGenerator($naming, $chainGuesser, $chainGuesser);
        $normGenerator = new NormalizerGenerator($naming, isset($options['reference']) ? $options['reference'] : true);

        return new self($serializer, $chainGuesser, $modelGenerator, $normGenerator);
    }

    public static function buildSerializer()
    {
        $encoders = [new JsonEncoder(new JsonEncode(JSON_UNESCAPED_SLASHES), new JsonDecode(false)), new RawEncoder()];
        $normalizers = NormalizerFactory::create();

        return new Serializer($normalizers, $encoders);
    }
}
