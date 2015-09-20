<?php

namespace Joli\Jane\Generator\Model;

use Joli\Jane\Generator\Naming;

use Joli\Jane\Guesser\Guess\Type;
use PhpParser\Comment\Doc;

use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Expr;

trait GetterSetterGenerator
{
    /**
     * @var \Joli\Jane\Generator\Naming
     */
    protected $naming;

    /**
     * Create get method
     *
     * @param $name
     * @param Type $type
     *
     * @return Stmt\ClassMethod
     */
    protected function createGetter($name, Type $type)
    {
        return new Stmt\ClassMethod(
            // getProperty
            $this->naming->getPrefixedMethodName('get', $name),
            [
                // public function
                'type' => Stmt\Class_::MODIFIER_PUBLIC,
                'stmts' => [
                    // return $this->property;
                    new Stmt\Return_(
                        new Expr\PropertyFetch(new Expr\Variable('this'), $this->naming->getPropertyName($name))
                    )
                ]
            ], [
                'comments' => [$this->createGetterDoc($type)]
            ]
        );
    }

    /**
     * Create set method
     *
     * @param $name
     * @param Type $type
     *
     * @return Stmt\ClassMethod
     */
    protected function createSetter($name, Type $type)
    {
        return new Stmt\ClassMethod(
            // setProperty
            $this->naming->getPrefixedMethodName('set', $name),
            [
                // public function
                'type' => Stmt\Class_::MODIFIER_PUBLIC,
                // ($property)
                'params' => [
                    new Param($this->naming->getPropertyName($name))
                ],
                'stmts' => [
                    // $this->property = $property;
                    new Expr\Assign(
                        new Expr\PropertyFetch(
                            new Expr\Variable('this'),
                            $this->naming->getPropertyName($name)
                        ), new Expr\Variable($this->naming->getPropertyName($name))
                    ),
                    // return $this;
                    new Stmt\Return_(new Expr\Variable('this'))
                ]
            ], [
                'comments' => [$this->createSetterDoc($name, $type)]
            ]
        );
    }

    /**
     * Return doc for get method
     *
     * @param Type $type
     *
     * @return Doc
     */
    protected function createGetterDoc(Type $type)
    {
        return new Doc(sprintf(<<<EOD
/**
 * @return %s
 */
EOD
        , $type->__toString()));
    }

    /**
     * Return doc for set method
     *
     * @param $name
     * @param Type $type
     *
     * @return Doc
     */
    protected function createSetterDoc($name, Type $type)
    {
        return new Doc(sprintf(<<<EOD
/**
 * @param %s %s
 *
 * @return self
 */
EOD
        , $type->__toString(), '$'.$this->naming->getPropertyName($name)));
    }
} 