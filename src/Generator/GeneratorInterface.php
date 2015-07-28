<?php

namespace Joli\Jane\Generator;

use Joli\Jane\Generator\Context\Context;
use Joli\Jane\Model\JsonSchema;

interface GeneratorInterface
{
    /**
     * Generate a set of files given a schema
     *
     * @param JsonSchema  $schema     Schema to generate from
     * @param string  $className  Class to generate
     * @param Context $context    Context for generation
     *
     * @return \Memio\Model\File[]
     */
    public function generate(JsonSchema $schema, $className, Context $context);
}
