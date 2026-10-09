<?php

namespace Tusk\Validation\Tests\Constraint;

use PHPUnit\Framework\TestCase;
use Tusk\Validation\Constraint\Email;
use Tusk\Validation\Constraint\Length;
use Tusk\Validation\Constraint\NotBlank;

final class ConstraintTest extends TestCase
{
    public function test_constraints_expose_valid_options(): void
    {
        self::assertSame(2, (new Length(min: 2, max: 8))->min);
        self::assertSame(8, (new Length(min: 2, max: 8))->max);
        self::assertInstanceOf(NotBlank::class, new NotBlank);
        self::assertInstanceOf(Email::class, new Email);
    }

    public function test_length_rejects_negative_minimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Length(min: -1);
    }

    public function test_length_rejects_maximum_smaller_than_minimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Length(min: 4, max: 3);
    }
}
