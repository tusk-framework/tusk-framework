<?php

declare(strict_types=1);

namespace Tests\Integration;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tusk\Foundation\Application;
use Tusk\Validation\Constraint\Length;
use Tusk\Validation\Constraint\NotBlank;

final class TypedCrudApplicationIntegrationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/tusk-typed-crud-'.bin2hex(random_bytes(8));
        mkdir($this->basePath.'/app/Controller', 0777, true);
        mkdir($this->basePath.'/bootstrap', 0777, true);

        file_put_contents($this->basePath.'/app/Controller/ProductController.php', <<<'PHP'
<?php
namespace TuskIssue11Fixture;

use Psr\Http\Message\ResponseInterface;
use Tusk\Web\Attribute\Controller;
use Tusk\Web\Attribute\Delete;
use Tusk\Web\Attribute\Get;
use Tusk\Web\Attribute\Post;
use Tusk\Web\Attribute\Put;
use Tusk\Web\Http\Response;
use Tests\Integration\CreateProductInput;
use Tests\Integration\ProductCatalog;

#[Controller('/api/products')]
final class ProductController
{
    public function __construct(private ProductCatalog $products) {}

    #[Get]
    public function index(): array
    {
        return $this->products->all();
    }

    #[Post]
    public function create(CreateProductInput $input): ResponseInterface
    {
        return Response::json($this->products->create($input->name, $input->sku), 201);
    }

    #[Get('/{id}')]
    public function show(int $id): ResponseInterface
    {
        $product = $this->products->find($id);

        return $product === null
            ? Response::json(['message' => 'Product not found'], 404)
            : Response::json($product);
    }

    #[Put('/{id}')]
    public function update(int $id, CreateProductInput $input): ResponseInterface
    {
        $product = $this->products->update($id, $input->name, $input->sku);

        return $product === null
            ? Response::json(['message' => 'Product not found'], 404)
            : Response::json($product);
    }

    #[Delete('/{id}')]
    public function delete(int $id): ResponseInterface
    {
        return $this->products->delete($id)
            ? new \Nyholm\Psr7\Response(204)
            : Response::json(['message' => 'Product not found'], 404);
    }
}
PHP);

        file_put_contents($this->basePath.'/bootstrap/providers.php', <<<'PHP'
<?php
return static function (\Tusk\Core\Container\Container $container): void {
    $container->instance(\Tests\Integration\ProductCatalog::class, new \Tests\Integration\ProductCatalog());
};
PHP);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->basePath);
    }

    public function test_booted_application_runs_typed_product_crud_and_preserves_service_state_between_requests(): void
    {
        $application = $this->application();

        self::assertSame([], $this->json($application->handle(new ServerRequest('GET', '/api/products'))));

        $created = $application->handle($this->jsonRequest('POST', '/api/products', ['name' => 'Notebook', 'sku' => 'NOTE-1']));
        self::assertSame(201, $created->getStatusCode());
        self::assertSame(['id' => 1, 'name' => 'Notebook', 'sku' => 'NOTE-1'], $this->json($created));

        $listed = $application->handle(new ServerRequest('GET', '/api/products'));
        self::assertSame([['id' => 1, 'name' => 'Notebook', 'sku' => 'NOTE-1']], $this->json($listed));
        self::assertSame(['id' => 1, 'name' => 'Notebook', 'sku' => 'NOTE-1'], $this->json($application->handle(new ServerRequest('GET', '/api/products/1'))));

        $updated = $application->handle($this->jsonRequest('PUT', '/api/products/1', ['name' => 'Journal', 'sku' => 'JOURNAL-1']));
        self::assertSame(200, $updated->getStatusCode());
        self::assertSame(['id' => 1, 'name' => 'Journal', 'sku' => 'JOURNAL-1'], $this->json($updated));

        self::assertSame(204, $application->handle(new ServerRequest('DELETE', '/api/products/1'))->getStatusCode());
        self::assertSame(404, $application->handle(new ServerRequest('GET', '/api/products/1'))->getStatusCode());
    }

    public function test_invalid_dto_returns_problem_details_without_echoing_submitted_values(): void
    {
        $application = $this->application();
        $response = $application->handle($this->jsonRequest('POST', '/api/products', ['name' => '   ', 'sku' => 'SECRET-INPUT']));
        $problem = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('urn:tusk:problem:validation', $problem['type']);
        self::assertArrayHasKey('name', $problem['errors']);
        self::assertStringNotContainsString('SECRET-INPUT', (string) $response->getBody());
    }

    public function test_missing_dto_field_and_missing_product_return_documented_client_errors(): void
    {
        $application = $this->application();

        $created = $application->handle($this->jsonRequest('POST', '/api/products', ['name' => 'Notebook', 'sku' => 'NOTE-1']));
        self::assertSame(201, $created->getStatusCode());

        $missingField = $application->handle($this->jsonRequest('POST', '/api/products', ['name' => 'Notebook']));
        self::assertSame(422, $missingField->getStatusCode());
        self::assertSame('application/problem+json', $missingField->getHeaderLine('Content-Type'));
        self::assertSame('Missing request field: sku', $this->json($missingField)['title']);
        self::assertSame([['id' => 1, 'name' => 'Notebook', 'sku' => 'NOTE-1']], $this->json($application->handle(new ServerRequest('GET', '/api/products'))));

        $missingProduct = $application->handle(new ServerRequest('GET', '/api/products/404'));
        self::assertSame(404, $missingProduct->getStatusCode());
        self::assertSame(['message' => 'Product not found'], $this->json($missingProduct));
    }

    private function application(): Application
    {
        return Application::configure($this->basePath)
            ->withProviders(['bootstrap/providers.php'])
            ->create();
    }

    private function jsonRequest(string $method, string $uri, array $payload): ServerRequest
    {
        return (new ServerRequest($method, $uri))
            ->withHeader('Accept', 'application/json')
            ->withParsedBody($payload);
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}

final readonly class CreateProductInput
{
    public function __construct(
        #[NotBlank]
        #[Length(max: 120)]
        public string $name,
        #[NotBlank]
        #[Length(max: 40)]
        public string $sku,
    ) {}
}

final readonly class ProductRecord
{
    public function __construct(public int $id, public string $name, public string $sku) {}
}

final class ProductCatalog
{
    /** @var array<int, ProductRecord> */
    private array $products = [];

    private int $nextId = 1;

    /** @return list<ProductRecord> */
    public function all(): array
    {
        return array_values($this->products);
    }

    public function find(int $id): ?ProductRecord
    {
        return $this->products[$id] ?? null;
    }

    public function create(string $name, string $sku): ProductRecord
    {
        $product = new ProductRecord($this->nextId++, $name, $sku);
        $this->products[$product->id] = $product;

        return $product;
    }

    public function update(int $id, string $name, string $sku): ?ProductRecord
    {
        if (! isset($this->products[$id])) {
            return null;
        }

        return $this->products[$id] = new ProductRecord($id, $name, $sku);
    }

    public function delete(int $id): bool
    {
        if (! isset($this->products[$id])) {
            return false;
        }
        unset($this->products[$id]);

        return true;
    }
}
