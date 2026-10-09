<?php

namespace Tusk\Validation\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tusk\Validation\ConstraintValidator;
use Tusk\Validation\Metadata\ValidationMetadataCompiler;
use Tusk\Validation\ValidationResult;
use Tusk\Validation\Validator;
use Tusk\Validation\Violation;

final class ValidatorTest extends TestCase
{
    public function test_constructor_values_are_required_by_validator_contract(): void
    {
        foreach ([\Tusk\Validation\ValidatorInterface::class, Validator::class] as $class) {
            $parameters = [];
            foreach ((new ReflectionMethod($class, 'validate'))->getParameters() as $parameter) {
                $parameters[$parameter->getName()] = $parameter;
            }
            self::assertArrayHasKey('constructorValues', $parameters);
            self::assertFalse($parameters['constructorValues']->isOptional(), sprintf('%s::validate() must require constructor values.', $class));
        }
    }

    public function test_aggregates_builtin_violations_in_field_and_attribute_order(): void
    {
        $metadata = (new ValidationMetadataCompiler)->compile(ValidatedInput::class);
        $result = (new Validator(new ConstraintValidator))->validate(
            new ValidatedInput('', 'submitted-sentinel'),
            $metadata,
            constructorValues: ['name' => '', 'email' => 'submitted-sentinel'],
        );

        self::assertInstanceOf(ValidationResult::class, $result);
        self::assertFalse($result->isValid());
        self::assertSame(['name', 'name', 'email'], array_map(
            static fn (Violation $violation): string => $violation->field(),
            $result->violations(),
        ));
        self::assertSame(['not_blank', 'length.min', 'email'], array_map(
            static fn (Violation $violation): string => $violation->code(),
            $result->violations(),
        ));
        self::assertStringNotContainsString('submitted-sentinel', serialize($result));
    }

    public function test_accepts_valid_input_and_custom_validator_violations(): void
    {
        $metadata = (new ValidationMetadataCompiler)->compile(ValidatedInput::class);
        $valid = new ValidatedInput('Ada', 'ada@example.test');
        $result = (new Validator(new ConstraintValidator))->validate(
            $valid,
            $metadata,
            constructorValues: ['name' => 'Ada', 'email' => 'ada@example.test'],
        );
        self::assertTrue($result->isValid());

        $custom = new class implements \Tusk\Validation\CustomValidatorInterface {
            public function validate(object $value): iterable
            {
                yield new Violation('name', 'custom.rule', 'Name is reserved.');
            }
        };
        $withCustom = (new Validator(new ConstraintValidator))->validate(
            $valid,
            $metadata,
            ['name' => 'Ada', 'email' => 'ada@example.test'],
            [$custom],
        );
        self::assertSame('custom.rule', $withCustom->violations()[0]->code());
    }

    public function test_not_blank_length_and_encoding_codes_are_stable(): void
    {
        $metadata = (new ValidationMetadataCompiler)->compile(ValidatedInput::class);
        $result = (new Validator(new ConstraintValidator))->validate(
            new ValidatedInput("\xFF", ''),
            $metadata,
            constructorValues: ['name' => "\xFF", 'email' => ''],
        );

        self::assertContains('length.invalid_encoding', array_map(
            static fn (Violation $violation): string => $violation->code(),
            $result->violations(),
        ));
    }

    public function test_length_max_code_and_utf8_code_point_counting(): void
    {
        $metadata = (new ValidationMetadataCompiler)->compile(BoundaryInput::class);
        $validator = new Validator(new ConstraintValidator);

        $tooLong = $validator->validate(
            new BoundaryInput('ab', 'ok@example.test', 'xx'),
            $metadata,
            constructorValues: ['text' => 'ab', 'email' => 'ok@example.test', 'symbol' => 'xx'],
        );
        self::assertSame('length.max', $tooLong->violations()[0]->code());

        $singleCodePoint = $validator->validate(
            new BoundaryInput('a', 'ok@example.test', 'é'),
            $metadata,
            constructorValues: ['text' => 'a', 'email' => 'ok@example.test', 'symbol' => 'é'],
        );
        self::assertTrue($singleCodePoint->isValid(), 'A multibyte UTF-8 character counts as one code point.');
    }

    public function test_email_accepts_null_and_empty_and_not_blank_rejects_whitespace(): void
    {
        $metadata = (new ValidationMetadataCompiler)->compile(BoundaryInput::class);
        $validator = new Validator(new ConstraintValidator);

        foreach ([null, ''] as $email) {
            $result = $validator->validate(
                new BoundaryInput('text', $email, 'x'),
                $metadata,
                constructorValues: ['text' => 'text', 'email' => $email, 'symbol' => 'x'],
            );
            self::assertTrue($result->isValid());
        }

        $blank = $validator->validate(
            new BoundaryInput(" \t\n", null, 'x'),
            $metadata,
            constructorValues: ['text' => " \t\n", 'email' => null, 'symbol' => 'x'],
        );
        self::assertSame('not_blank', $blank->violations()[0]->code());
    }

    public function test_constructor_values_support_plain_parameters_private_properties_and_defaults(): void
    {
        $validator = new Validator(new ConstraintValidator);
        $metadata = (new ValidationMetadataCompiler)->compile(ConstructorValuesDto::class);

        $result = $validator->validate(
            new ConstructorValuesDto('value'),
            $metadata,
            constructorValues: ['plain' => 'value', 'private' => 'default-value'],
        );
        self::assertTrue($result->isValid());

        $invalid = $validator->validate(
            new ConstructorValuesDto('', ''),
            $metadata,
            constructorValues: ['plain' => '', 'private' => ''],
        );
        self::assertSame(['plain', 'private'], array_map(
            static fn (\Tusk\Validation\Violation $violation): string => $violation->field(),
            $invalid->violations(),
        ));
    }
}

final class ValidatedInput
{
    public function __construct(
        #[\Tusk\Validation\Constraint\NotBlank]
        #[\Tusk\Validation\Constraint\Length(min: 2, max: 4)]
        public readonly string $name,
        #[\Tusk\Validation\Constraint\Email]
        public readonly ?string $email,
    ) {
    }
}

final class BoundaryInput
{
    public function __construct(
        #[\Tusk\Validation\Constraint\NotBlank]
        public readonly string $text,
        #[\Tusk\Validation\Constraint\Email]
        public readonly ?string $email,
        #[\Tusk\Validation\Constraint\Length(min: 1, max: 1)]
        public readonly string $symbol,
    ) {
    }
}

final class ConstructorValuesDto
{
    public function __construct(
        #[\Tusk\Validation\Constraint\NotBlank] string $plain,
        #[\Tusk\Validation\Constraint\NotBlank] private readonly string $private = 'default-value',
    ) {
    }
}
