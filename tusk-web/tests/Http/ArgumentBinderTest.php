<?php

namespace Tusk\Web\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;
use Tusk\Contracts\Container\ContainerInterface;
use Tusk\Web\Http\ArgumentBinder;
use Tusk\Web\Http\HttpException;
use Tusk\Web\Http\Request;
use Tusk\Web\Http\ValidationException;
use Tusk\Validation\Constraint\Email;
use Tusk\Validation\Constraint\Length;
use Tusk\Validation\Constraint\NotBlank;
use Tusk\Validation\ConstraintValidator;
use Tusk\Validation\CustomValidatorInterface;
use Tusk\Validation\CustomValidatorRegistry;
use Tusk\Validation\Metadata\ValidationMetadataCompiler;
use Tusk\Validation\Metadata\ValidationMetadataRegistry;
use Tusk\Validation\Validator;
use Tusk\Validation\Violation;

final class ArgumentBinderTest extends TestCase
{
    private function validatingBinder(): ArgumentBinder
    {
        $metadata = new ValidationMetadataRegistry;
        $metadata->register(ValidatedInput::class, (new ValidationMetadataCompiler)->compile(ValidatedInput::class));
        $metadata->register(DefaultedInput::class, (new ValidationMetadataCompiler)->compile(DefaultedInput::class));
        $metadata->seal();
        $custom = new CustomValidatorRegistry;
        $custom->register(ValidatedInput::class, new RejectedNameValidator);
        $custom->seal();

        return new ArgumentBinder(new Validator(new ConstraintValidator), $metadata, $custom);
    }

    public function test_valid_dto_is_passed_unchanged_with_converted_and_defaulted_values(): void
    {
        $arguments = $this->validatingBinder()->bind(
            new ReflectionMethod(BindableController::class, 'validated'),
            (new ServerRequest('POST', '/'))->withParsedBody(['name' => 'Ada', 'email' => 'ada@example.com']),
            [],
            new EmptyContainer,
        );

        self::assertInstanceOf(ValidatedInput::class, $arguments[0]);
        self::assertSame('Ada', $arguments[0]->name);
        self::assertSame('ada@example.com', $arguments[0]->email);
        self::assertSame('ok', $arguments[0]->note);
    }

    public function test_builtin_and_custom_violations_aggregate_across_fields(): void
    {
        try {
            $this->validatingBinder()->bind(
                new ReflectionMethod(BindableController::class, 'validated'),
                (new ServerRequest('POST', '/'))->withParsedBody(['name' => ' ', 'email' => 'bad', 'note' => '']),
                [],
                new EmptyContainer,
            );
            self::fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            self::assertSame([
                'name' => [['code' => 'not_blank', 'message' => 'This value should not be blank.'], ['code' => 'name.rejected', 'message' => 'Name is rejected.']],
                'email' => [['code' => 'email', 'message' => 'This value is not a valid email address.']],
                'note' => [['code' => 'length.min', 'message' => 'This value is too short.']],
            ], $exception->errors());
        }
    }

    public function test_optional_default_is_included_in_validation_values(): void
    {
        try {
            $this->validatingBinder()->bind(
                new ReflectionMethod(BindableController::class, 'defaulted'),
                (new ServerRequest('POST', '/'))->withParsedBody([]),
                [],
                new EmptyContainer,
            );
            self::fail('Expected the defaulted value to be validated.');
        } catch (ValidationException $exception) {
            self::assertSame(['name' => [['code' => 'not_blank', 'message' => 'This value should not be blank.']]], $exception->errors());
        }
    }

    public function test_request_and_psr_request_bypass_dto_validation(): void
    {
        $request = new ServerRequest('GET', '/');
        $binder = $this->validatingBinder();
        self::assertInstanceOf(Request::class, $binder->bind(new ReflectionMethod(BindableController::class, 'withRequest'), $request, [], new EmptyContainer)[0]);
        self::assertSame($request, $binder->bind(new ReflectionMethod(BindableController::class, 'withPsrRequest'), $request, [], new EmptyContainer)[0]);
    }

    public function test_missing_and_invalid_scalars_stay_binding_errors_before_validation(): void
    {
        foreach ([[], ['name' => 'Ada', 'email' => 'ada@example.com', 'quantity' => 'no']] as $payload) {
            try {
                $this->validatingBinder()->bind(new ReflectionMethod(BindableController::class, 'validated'), (new ServerRequest('POST', '/'))->withParsedBody($payload), [], new EmptyContainer);
                self::fail('Expected binding error.');
            } catch (HttpException $exception) {
                self::assertNotInstanceOf(ValidationException::class, $exception);
                self::assertSame(422, $exception->getStatusCode());
            }
        }
    }
    public function test_binds_route_scalars_request_wrapper_and_typed_body_dto(): void
    {
        $request = (new ServerRequest('POST', '/users/42'))
            ->withParsedBody(['name' => 'Ana']);

        $arguments = (new ArgumentBinder)->bind(
            new ReflectionMethod(BindableController::class, 'create'),
            $request,
            ['id' => '42'],
            new EmptyContainer,
        );

        $this->assertSame(42, $arguments[0]);
        $this->assertInstanceOf(CreateUserRequest::class, $arguments[1]);
        $this->assertSame('Ana', $arguments[1]->name);
        $this->assertInstanceOf(Request::class, $arguments[2]);
    }

    public function test_injects_the_original_psr_server_request(): void
    {
        $request = new ServerRequest('GET', '/users');

        $arguments = (new ArgumentBinder)->bind(
            new ReflectionMethod(BindableController::class, 'withPsrRequest'),
            $request,
            [],
            new EmptyContainer,
        );

        $this->assertSame($request, $arguments[0]);
    }

    public function test_hydrates_readonly_dto_scalars_and_preserves_constructor_defaults(): void
    {
        $request = (new ServerRequest('POST', '/products'))
            ->withParsedBody([
                'quantity' => '3',
                'price' => '12.50',
                'active' => 'false',
                'name' => 'Widget',
                'tags' => ['featured', 'new'],
            ]);

        $arguments = (new ArgumentBinder)->bind(
            new ReflectionMethod(BindableController::class, 'createProduct'),
            $request,
            [],
            new EmptyContainer,
        );

        $input = $arguments[0];
        $this->assertInstanceOf(ProductRequest::class, $input);
        $this->assertSame(3, $input->quantity);
        $this->assertSame(12.5, $input->price);
        $this->assertFalse($input->active);
        $this->assertSame('Widget', $input->name);
        $this->assertSame('standard', $input->category);
        $this->assertSame(['featured', 'new'], $input->tags);
    }

    public function test_missing_required_dto_field_is_a_stable_422_error(): void
    {
        $request = (new ServerRequest('POST', '/products'))
            ->withParsedBody(['quantity' => '3']);

        try {
            (new ArgumentBinder)->bind(
                new ReflectionMethod(BindableController::class, 'createProduct'),
                $request,
                [],
                new EmptyContainer,
            );
            self::fail('Expected a missing required DTO field to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('Missing request field: price', $exception->getMessage());
        }
    }

    public function test_invalid_dto_scalar_is_a_stable_422_error(): void
    {
        $request = (new ServerRequest('POST', '/products'))
            ->withParsedBody([
                'quantity' => 'many',
                'price' => '12.50',
                'active' => 'false',
                'name' => 'Widget',
            ]);

        try {
            (new ArgumentBinder)->bind(
                new ReflectionMethod(BindableController::class, 'createProduct'),
                $request,
                [],
                new EmptyContainer,
            );
            self::fail('Expected an invalid DTO scalar to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('Invalid value for argument: quantity', $exception->getMessage());
        }
    }
}

final class BindableController
{
    public function create(int $id, CreateUserRequest $input, Request $request): array
    {
        return compact('id', 'input', 'request');
    }

    public function createProduct(ProductRequest $input): ProductRequest
    {
        return $input;
    }

    public function withPsrRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request;
    }

    public function validated(ValidatedInput $input): ValidatedInput { return $input; }

    public function defaulted(DefaultedInput $input): DefaultedInput { return $input; }

    public function withRequest(Request $request): Request { return $request; }
}

final readonly class ValidatedInput
{
    public function __construct(
        #[NotBlank] public string $name,
        #[Email] public string $email,
        public int $quantity = 1,
        #[Length(min: 2)] public string $note = 'ok',
    ) {}
}

final readonly class DefaultedInput
{
    public function __construct(#[NotBlank] public string $name = '') {}
}

final class RejectedNameValidator implements CustomValidatorInterface
{
    public function validate(object $value): iterable
    {
        if ($value instanceof ValidatedInput && trim($value->name) === '') {
            yield new Violation('name', 'name.rejected', 'Name is rejected.');
        }
    }
}

final readonly class CreateUserRequest
{
    public function __construct(public string $name) {}
}

final readonly class ProductRequest
{
    public function __construct(
        public int $quantity,
        public float $price,
        public bool $active,
        public string $name,
        public string $category = 'standard',
        public array $tags = [],
    ) {}
}

final class EmptyContainer implements ContainerInterface
{
    public function instance(string $id, object $instance): void {}

    public function get(string $id): object
    {
        throw new \RuntimeException("Missing {$id}");
    }

    public function has(string $id): bool
    {
        return false;
    }

    public function runHooks(string $attributeClass): void {}

    public function runLifecycleHooks(string $event): void {}

    public function resetScope(string $scope): void {}
}
