<?php

namespace Tusk\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Tusk\Validation\CustomValidatorInterface;
use Tusk\Validation\CustomValidatorRegistry;
use Tusk\Validation\Metadata\ValidationMetadata;
use Tusk\Validation\Metadata\ValidationMetadataRegistry;

final class CustomValidatorRegistryTest extends TestCase
{
    public function test_returns_only_validators_registered_for_the_requested_dto_in_order(): void
    {
        $first = new RegistryValidator;
        $second = new RegistryValidator;
        $registry = new CustomValidatorRegistry;
        $registry->register('FirstDto', $first);
        $registry->register('OtherDto', new RegistryValidator);
        $registry->register('FirstDto', $second);

        self::assertSame([$first, $second], $registry->for('FirstDto'));
        self::assertSame([], $registry->for('UnknownDto'));
    }

    public function test_metadata_registry_rejects_duplicate_registration_and_changes_after_sealing(): void
    {
        $registry = new ValidationMetadataRegistry;
        $metadata = new ValidationMetadata([]);
        $registry->register('FirstDto', $metadata);
        self::assertSame($metadata, $registry->metadataFor('FirstDto'));

        try {
            $registry->register('FirstDto', $metadata);
            self::fail('Duplicate metadata was accepted.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('FirstDto', $exception->getMessage());
        }

        $registry->seal();
        $this->expectException(\LogicException::class);
        $registry->register('OtherDto', $metadata);
    }

    public function test_metadata_registry_reports_missing_dto(): void
    {
        $registry = new ValidationMetadataRegistry;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('MissingDto');
        $registry->metadataFor('MissingDto');
    }
}

final class RegistryValidator implements CustomValidatorInterface
{
    public function validate(object $value): iterable
    {
        return [];
    }
}
