<?php

namespace Tusk\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Tusk\Validation\ConstraintValidator;
use Tusk\Validation\Metadata\ValidationMetadataCompiler;
use Tusk\Validation\ValidationResult;
use Tusk\Validation\Validator;
use Tusk\Validation\Violation;

final class ValidatorTest extends TestCase
{
    public function test_aggregates_builtin_violations_in_field_and_attribute_order(): void
    {
        $metadata = (new ValidationMetadataCompiler)->compile(ValidatedInput::class);
        $result = (new Validator(new ConstraintValidator))->validate(new ValidatedInput('', 'invalid'), $metadata);

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
        self::assertStringNotContainsString('invalid', json_encode($result->violations()));
    }

    public function test_accepts_valid_input_and_custom_validator_violations(): void
    {
        $metadata = (new ValidationMetadataCompiler)->compile(ValidatedInput::class);
        $valid = new ValidatedInput('Ada', 'ada@example.test');
        $result = (new Validator(new ConstraintValidator))->validate($valid, $metadata);
        self::assertTrue($result->isValid());

        $custom = new class implements \Tusk\Validation\CustomValidatorInterface {
            public function validate(object $value): iterable
            {
                yield new Violation('name', 'custom.rule', 'Name is reserved.');
            }
        };
        $withCustom = (new Validator(new ConstraintValidator))->validate($valid, $metadata, [$custom]);
        self::assertSame('custom.rule', $withCustom->violations()[0]->code());
    }

    public function test_not_blank_length_and_encoding_codes_are_stable(): void
    {
        $metadata = (new ValidationMetadataCompiler)->compile(ValidatedInput::class);
        $result = (new Validator(new ConstraintValidator))->validate(new ValidatedInput("\xFF", ''), $metadata);

        self::assertContains('length.invalid_encoding', array_map(
            static fn (Violation $violation): string => $violation->code(),
            $result->violations(),
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
