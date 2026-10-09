<?php

namespace Tusk\Validation\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use Tusk\Validation\Constraint\Email;
use Tusk\Validation\Constraint\Length;
use Tusk\Validation\Constraint\NotBlank;
use Tusk\Validation\Metadata\ValidationMetadataCompiler;

final class ValidationMetadataCompilerTest extends TestCase
{
    public function test_compiles_promoted_parameter_constraints_in_declaration_order(): void
    {
        $metadata = (new ValidationMetadataCompiler)->compile(CompilerDto::class);

        self::assertSame(['name', 'email'], $metadata->fields());
        self::assertSame([NotBlank::class, Length::class], array_map(
            static fn (object $constraint): string => $constraint::class,
            $metadata->constraintsFor('name'),
        ));
        self::assertSame([Email::class], array_map(
            static fn (object $constraint): string => $constraint::class,
            $metadata->constraintsFor('email'),
        ));
    }

    public function test_rejects_constraint_on_non_promoted_property(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('property');

        (new ValidationMetadataCompiler)->compile(PropertyConstraintDto::class);
    }

    public function test_rejects_constraint_on_non_string_constructor_parameter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('string');

        (new ValidationMetadataCompiler)->compile(InvalidTypeDto::class);
    }
}

final class CompilerDto
{
    public function __construct(
        #[NotBlank]
        #[Length(min: 2)]
        public readonly string $name,
        #[Email]
        public readonly ?string $email = null,
    ) {
    }
}

final class PropertyConstraintDto
{
    #[NotBlank]
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

final class InvalidTypeDto
{
    public function __construct(#[NotBlank] public readonly int $count)
    {
    }
}
