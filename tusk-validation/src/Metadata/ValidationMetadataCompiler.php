<?php

namespace Tusk\Validation\Metadata;

use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use Tusk\Validation\Constraint\Email;
use Tusk\Validation\Constraint\Length;
use Tusk\Validation\Constraint\NotBlank;

final class ValidationMetadataCompiler
{
    /** @var list<class-string> */
    private const CONSTRAINTS = [NotBlank::class, Email::class, Length::class];

    public function compile(string $dtoClass): ValidationMetadata
    {
        if (!class_exists($dtoClass)) {
            throw new InvalidArgumentException(sprintf('Validation DTO class "%s" does not exist.', $dtoClass));
        }

        $class = new ReflectionClass($dtoClass);
        foreach ($class->getProperties() as $property) {
            // PHP exposes constructor-parameter attributes on promoted properties too.
            if ($property->isPromoted()) {
                continue;
            }
            foreach ($property->getAttributes() as $attribute) {
                if (in_array($attribute->getName(), self::CONSTRAINTS, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Constraint %s on property %s::$%s is unsupported; place it on a constructor parameter.',
                        $attribute->getName(), $dtoClass, $property->getName(),
                    ));
                }
            }
        }

        $constructor = $class->getConstructor();
        $constraints = [];
        if ($constructor === null) {
            return new ValidationMetadata($constraints);
        }

        foreach ($constructor->getParameters() as $parameter) {
            $attributes = [];
            foreach ($parameter->getAttributes() as $attribute) {
                if (in_array($attribute->getName(), self::CONSTRAINTS, true)) {
                    $attributes[] = $attribute;
                }
            }
            if ($attributes === []) {
                continue;
            }

            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->getName() !== 'string') {
                throw new InvalidArgumentException(sprintf(
                    'Constraints on %s::$%s require a string or nullable string constructor parameter.',
                    $dtoClass, $parameter->getName(),
                ));
            }

            foreach ($attributes as $attribute) {
                $constraints[$parameter->getName()][] = $attribute->newInstance();
            }
        }

        return new ValidationMetadata($constraints);
    }
}
