<?php

namespace Joli\Jane\Command;

use Joli\Jane\Registry;
use Joli\Jane\Schema;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GenerateCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    public function configure()
    {
        $this->setName('generate');
        $this->setDescription('Generate a set of class and normalizers given a specific Json Schema file');
        $this->addOption('config-file', 'c', InputOption::VALUE_OPTIONAL, 'File to use for jane configuration');
        $this->addOption('no-reference', null, InputOption::VALUE_NONE, 'Don\'t use the reference system in your generated schema');
        $this->addOption('date-format', 'd', InputOption::VALUE_OPTIONAL, 'Date time format to use for date time field');
        $this->addOption('fixer-config-file', null, InputOption::VALUE_REQUIRED, 'File to use for php-cs-fixer configuration');
        $this->addArgument('json-schema-file', InputArgument::OPTIONAL, 'Location of the Json Schema file');
        $this->addArgument('root-class', InputArgument::OPTIONAL, 'Name of the root entity you want to generate');
        $this->addArgument('namespace', InputArgument::OPTIONAL, 'Namespace prefix to use for generated files');
        $this->addArgument('directory', InputArgument::OPTIONAL, 'Directory where to generate files');
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $options = [];

        if ($input->hasOption('config-file') && null !== $input->getOption('config-file')) {
            $configFile = $input->getOption('config-file');

            if (!file_exists($configFile)) {
                throw new \RuntimeException(sprintf('Config file %s does not exist', $configFile));
            }

            $options = require $configFile;

            if (!is_array($options)) {
                throw new \RuntimeException(sprintf('Invalid config file specified or invalid return type in file %s', $configFile));
            }
        } else {
            if ($input->hasArgument('json-schema-file') && null !== $input->getArgument('json-schema-file')) {
                $options['json-schema-file'] = $input->getArgument('json-schema-file');
            }

            if ($input->hasArgument('root-class') && null !== $input->getArgument('root-class')) {
                $options['root-class'] = $input->getArgument('root-class');
            }

            if ($input->hasArgument('directory') && null !== $input->getArgument('directory')) {
                $options['directory'] = $input->getArgument('directory');
            }

            if ($input->hasArgument('namespace') && null !== $input->getArgument('namespace')) {
                $options['namespace'] = $input->getArgument('namespace');
            }

            if ($input->hasOption('date-format') && null !== $input->getOption('date-format')) {
                $options['date-format'] = $input->getOption('date-format');
            }

            if ($input->hasOption('no-reference') && null !== $input->getOption('no-reference')) {
                $options['reference'] = !$input->getOption('no-reference');
            }
        }

        $options  = $this->resolveConfiguration($options);
        $registry = new Registry();

        if (array_key_exists('json-schema-file', $options)) {
            $registry->addSchema($this->resolveSchema($options['json-schema-file'], $options));
        } else {
            foreach ($options['mapping'] as $schema => $schemaOptions) {
                $registry->addSchema($this->resolveSchema($schema, $schemaOptions));
            }
        }

        $jane = \Joli\Jane\Jane::build($options);

        if ($input->hasOption('fixer-config-file') && null !== $input->getOption('fixer-config-file')) {
            $fixerConfigFile = $input->getOption('fixer-config-file');

            if (!file_exists($fixerConfigFile)) {
                throw new \RuntimeException(sprintf('Fixer config file %s could not be found', $fixerConfigFile));
            }

            $configFile = require $fixerConfigFile;
            $jane->setFixerConfig($configFile);
        }

        $files = $jane->generate($registry);

        foreach ($files as $file) {
            $output->writeln(sprintf("Generated %s", $file));
        }
    }

    protected function resolveConfiguration(array $options = [])
    {
        $optionsResolver = new OptionsResolver();
        $optionsResolver->setDefaults([
            'reference' => true,
            'date-format' => \DateTime::RFC3339,
        ]);

        if (array_key_exists('json-schema-file', $options)) {
            $optionsResolver->setRequired([
                'json-schema-file',
                'root-class',
                'namespace',
                'directory',
            ]);
        } else {
            $optionsResolver->setRequired([
                'mapping'
            ]);
        }

        return $optionsResolver->resolve($options);
    }

    protected function resolveSchema($schema, array $options = [])
    {
        $optionsResolver = new OptionsResolver();

        // To support old schema
        $optionsResolver->setDefined([
            'json-schema-file',
            'reference',
            'date-format',
        ]);

        $optionsResolver->setRequired([
            'root-class',
            'namespace',
            'directory',
        ]);

        $options = $optionsResolver->resolve($options);

        return new Schema($schema, $options['namespace'], $options['directory'], $options['root-class']);
    }
}
