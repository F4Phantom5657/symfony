<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\PropertyInfo\Extractor;

use Symfony\Component\Inflector\Inflector;
use Symfony\Component\PropertyInfo\PropertyAccessExtractorInterface;
use Symfony\Component\PropertyInfo\PropertyListExtractorInterface;
use Symfony\Component\PropertyInfo\PropertyTypeExtractorInterface;
use Symfony\Component\PropertyInfo\Type;

/**
 * Extracts data using the reflection API.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 *
 * @final since version 3.3
 */
class ReflectionExtractor implements PropertyListExtractorInterface, PropertyTypeExtractorInterface, PropertyAccessExtractorInterface
{
    /**
     * @internal
     *
     * @var string[]
     */
    public static $mutatorPrefixes = array('add', 'remove', 'set');

    /**
     * @internal
     *
     * @var string[]
     */
    public static $accessorPrefixes = array('is', 'can', 'get');

    /**
     * @internal
     *
     * @var string[]
     */
    public static $arrayMutatorPrefixes = array('add', 'remove');

    /**
     * {@inheritdoc}
     */
    public function getProperties($class, array $context = array())
    {
        try {
            $reflectionClass = new \ReflectionClass($class);
        } catch (\ReflectionException $e) {
            return;
        }

        $reflectionProperties = $reflectionClass->getProperties();

        $properties = array();
        foreach ($reflectionProperties as $reflectionProperty) {
            if ($reflectionProperty->isPublic()) {
                $properties[$reflectionProperty->name] = $reflectionProperty->name;
            }
        }

        foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            if ($reflectionMethod->isStatic()) {
                continue;
            }

            $propertyName = $this->getPropertyName($reflectionMethod->name, $reflectionProperties);
            if (!$propertyName || isset($properties[$propertyName])) {
                continue;
            }
            if (!$reflectionClass->hasProperty($propertyName) && !preg_match('/^[A-Z]{2,}/', $propertyName)) {
                $propertyName = lcfirst($propertyName);
            }
            $properties[$propertyName] = $propertyName;
        }

        return array_values($properties);
    }

    /**
     * {@inheritdoc}
     */
    public function getTypes($class, $property, array $context = array())
    {
        if ($fromMutator = $this->extractFromMutator($class, $property)) {
            return $fromMutator;
        }

        if ($fromAccessor = $this->extractFromAccessor($class, $property)) {
            return $fromAccessor;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable($class, $property, array $context = array())
    {
        if ($this->isPublicProperty($class, $property)) {
            return true;
        }

        list($reflectionMethod) = $this->getAccessorMethod($class, $property);

        return null !== $reflectionMethod;
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable($class, $property, array $context = array())
    {
        if ($this->isPublicProperty($class, $property)) {
            return true;
        }

        list($reflectionMethod) = $this->getMutatorMethod($class, $property);

        return null !== $reflectionMethod;
    }

    /**
     * Tries to extract type information from mutators.
     *
     * @param string $class
     * @param string $property
     *
     * @return Type[]|null
     */
    private function extractFromMutator($class, $property)
    {
        list($reflectionMethod, $prefix) = $this->getMutatorMethod($class, $property);
        if (null === $reflectionMethod) {
            return;
        }

        $reflectionParameters = $reflectionMethod->getParameters();
        $reflectionParameter = $reflectionParameters[0];

        if (!$reflectionType = $reflectionParameter->getType()) {
            return;
        }
        $type = $this->extractFromReflectionType($reflectionType);

        // HHVM reports variadics with "array" but not builtin type hints
        if (!$reflectionType->isBuiltin() && Type::BUILTIN_TYPE_ARRAY === $type->getBuiltinType()) {
            return;
        }

        if (in_array($prefix, self::$arrayMutatorPrefixes)) {
            $type = new Type(Type::BUILTIN_TYPE_ARRAY, false, null, true, new Type(Type::BUILTIN_TYPE_INT), $type);
        }

        return array($type);
    }

    /**
     * Tries to extract type information from accessors.
     *
     * @param string $class
     * @param string $property
     *
     * @return Type[]|null
     */
    private function extractFromAccessor($class, $property)
    {
        list($reflectionMethod, $prefix) = $this->getAccessorMethod($class, $property);
        if (null === $reflectionMethod) {
            return;
        }

        if ($reflectionType = $reflectionMethod->getReturnType()) {
            return array($this->extractFromReflectionType($reflectionType));
        }

        if (in_array($prefix, array('is', 'can'))) {
            return array(new Type(Type::BUILTIN_TYPE_BOOL));
        }
    }

    /**
     * Extracts data from the PHP 7 reflection type.
     *
     * @param \ReflectionType $reflectionType
     *
     * @return Type
     */
    private function extractFromReflectionType(\ReflectionType $reflectionType)
    {
        $phpTypeOrClass = $reflectionType->getName();
        $nullable = $reflectionType->allowsNull();

        if (Type::BUILTIN_TYPE_ARRAY === $phpTypeOrClass) {
            $type = new Type(Type::BUILTIN_TYPE_ARRAY, $nullable, null, true);
        } elseif ('void' === $phpTypeOrClass) {
            $type = new Type(Type::BUILTIN_TYPE_NULL, $nullable);
        } elseif ($reflectionType->isBuiltin()) {
            $type = new Type($phpTypeOrClass, $nullable);
        } else {
            $type = new Type(Type::BUILTIN_TYPE_OBJECT, $nullable, $phpTypeOrClass);
        }

        return $type;
    }

    /**
     * Does the class have the given public property?
     *
     * @param string $class
     * @param string $property
     *
     * @return bool
     */
    private function isPublicProperty($class, $property)
    {
        try {
            $reflectionProperty = new \ReflectionProperty($class, $property);

            return $reflectionProperty->isPublic();
        } catch (\ReflectionException $e) {
            // Return false if the property doesn't exist
        }

        return false;
    }

    /**
     * Gets the accessor method.
     *
     * Returns an array with a the instance of \ReflectionMethod as first key
     * and the prefix of the method as second or null if not found.
     *
     * @param string $class
     * @param string $property
     *
     * @return array|null
     */
    private function getAccessorMethod($class, $property)
    {
        $ucProperty = ucfirst($property);

        foreach (self::$accessorPrefixes as $prefix) {
            try {
                $reflectionMethod = new \ReflectionMethod($class, $prefix.$ucProperty);
                if ($reflectionMethod->isStatic()) {
                    continue;
                }

                if (0 === $reflectionMethod->getNumberOfRequiredParameters()) {
                    return array($reflectionMethod, $prefix);
                }
            } catch (\ReflectionException $e) {
                // Return null if the property doesn't exist
            }
        }
    }

    /**
     * Gets the mutator method.
     *
     * Returns an array with a the instance of \ReflectionMethod as first key
     * and the prefix of the method as second or null if not found.
     *
     * @param string $class
     * @param string $property
     *
     * @return array
     */
    private function getMutatorMethod($class, $property)
    {
        $ucProperty = ucfirst($property);
        $ucSingulars = (array) Inflector::singularize($ucProperty);

        foreach (self::$mutatorPrefixes as $prefix) {
            $names = array($ucProperty);
            if (in_array($prefix, self::$arrayMutatorPrefixes)) {
                $names = array_merge($names, $ucSingulars);
            }

            foreach ($names as $name) {
                try {
                    $reflectionMethod = new \ReflectionMethod($class, $prefix.$name);
                    if ($reflectionMethod->isStatic()) {
                        continue;
                    }

                    // Parameter can be optional to allow things like: method(array $foo = null)
                    if ($reflectionMethod->getNumberOfParameters() >= 1) {
                        return array($reflectionMethod, $prefix);
                    }
                } catch (\ReflectionException $e) {
                    // Try the next prefix if the method doesn't exist
                }
            }
        }
    }

    /**
     * Extracts a property name from a method name.
     *
     * @param string                $methodName
     * @param \ReflectionProperty[] $reflectionProperties
     *
     * @return string
     */
    private function getPropertyName($methodName, array $reflectionProperties)
    {
        $pattern = implode('|', array_merge(self::$accessorPrefixes, self::$mutatorPrefixes));

        if (preg_match('/^('.$pattern.')(.+)$/i', $methodName, $matches)) {
            if (!in_array($matches[1], self::$arrayMutatorPrefixes)) {
                return $matches[2];
            }

            foreach ($reflectionProperties as $reflectionProperty) {
                foreach ((array) Inflector::singularize($reflectionProperty->name) as $name) {
                    if (strtolower($name) === strtolower($matches[2])) {
                        return $reflectionProperty->name;
                    }
                }
            }

            return $matches[2];
        }
    }
}
