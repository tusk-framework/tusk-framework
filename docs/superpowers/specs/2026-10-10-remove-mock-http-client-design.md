# Remove the Unfinished Declarative HTTP Client

**Status:** Design approved in conversation; implementation awaits review of this specification and its plan.

## Goal

Remove the Framework's misleading, incomplete declarative HTTP-client proof of concept while retaining the tested PSR-18 resilience integration as the supported low-level outbound HTTP API.

## Current behavior

`Tusk\Cloud\Http\HttpClientFactory` accepts an interface but returns an anonymous object that does not implement it. Calls infer `GET /<methodName>`, print to stdout, and return a hard-coded array. The `ApiClient`, `Get`, and `Post` attributes suggest a public contract that is neither implemented nor tested.

The factory is the only consumer of the current discovery abstractions. `ConsulDiscoveryClient` uses raw `file_get_contents`, swallows all failures as empty results, and has no independent callers or tests. No Framework README or active guide documents this as a supported capability.

## Chosen approach

Delete the unfinished declarative client and its orphaned discovery implementation rather than building a new transport/proxy abstraction without a validated use case. Preserve `Tusk\Cloud\Resilience\Http\Psr18ResilientClient` unchanged; it implements PSR-18, has focused tests, and composes with the existing resilience pipeline.

## Scope

- Remove `tusk-cloud/src/Http/ApiClient.php`, `tusk-cloud/src/Http/HttpClientFactory.php`, and `tusk-cloud/src/Http/Methods.php`.
- Remove the discovery files that have no consumers outside the mock proof of concept: `tusk-cloud/src/Discovery/DiscoveryClientInterface.php`, `ServiceInstance.php`, and `ConsulDiscoveryClient.php`.
- Search repository documentation, examples, tests, and manifests for references; remove only references to these deleted APIs. Keep the PSR-18 resilience API and unrelated cloud/Resilience code intact.
- Update Framework issue #6 with the deletion decision, verification, and release evidence; close it after the merged change is verified.

## Compatibility and release

These classes are publicly autoloadable and their removal is a breaking change even though the implementation is a POC with no in-repository consumers. Follow the configured release rule for breaking changes and do not mislabel the change as patch/minor. The expected major release after `v0.3.2` is `v1.0.0`; confirm the release automation's selected tag before updating consumers. The App skeleton must move from `tusk-framework/framework:^0.3.2` to the published major constraint before it is publicly registered.

## Out of scope

- Replacing the POC with a typed declarative client, generated proxy, HTTP transport, service discovery, gateway, or service-mesh feature.
- Changing `Psr18ResilientClient`, its retry semantics, dependencies, or public contract.
- Removing general resilience, tracing, or RoadRunner capability integrations.
- Preserving aliases or deprecated shims for the deleted POC; no compatibility bridge is warranted for this unused pre-1.0 project code.

## Acceptance criteria

1. The three `Tusk\Cloud\Http` POC files and three orphaned discovery files are removed.
2. A repository search finds no active Framework source, tests, or user documentation importing `ApiClient`, `Get`, `Post`, `HttpClientFactory`, `DiscoveryClientInterface`, `ServiceInstance`, or `ConsulDiscoveryClient`.
3. The `Psr18ResilientClient` focused suite passes with its existing API and tests unchanged.
4. The complete Framework PHPUnit suite passes on supported PHP CI versions; local SQLite-dependent tests are run with `pdo_sqlite` enabled. No stdout/debug mock behavior remains.
5. The change is released according to the configured breaking-change policy, and the App consumer's stable constraint is updated to the actual published version before the public skeleton package is indexed.
6. Issue #6 links the merged PR and release; it is not closed on the basis of local deletion alone.
