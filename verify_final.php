<?php

require_once 'vendor/autoload.php';

use Tusk\Core\Container\Container;
use Tusk\Cloud\Resilience\CircuitBreaker;
use Tusk\Cloud\Resilience\InMemoryStateStore;

echo "--- Final Verification ---\n";

// 1. Verify Container Caching
echo "Verifying Container Caching...\n";
$container = new Container();
// Simulate registration
$container->setDefinitions(['test' => 'stdClass'], ['test' => 'singleton']);
assert($container->has('test'));
$export = $container->export();
assert(isset($export['definitions']['test']));
echo "Container Caching OK!\n";

// 2. Verify Distributed Circuit Breaker
echo "Verifying Circuit Breaker with StateStore...\n";
$store = new InMemoryStateStore();
$cb1 = new CircuitBreaker($store, 'api_service', 2, 1.0);
$cb2 = new CircuitBreaker($store, 'api_service', 2, 1.0); // same name, same store

try {
    $cb1->execute(fn() => throw new Exception("Fail 1"));
} catch (Exception $e) {
}

assert($cb2->getState()->name === 'CLOSED'); // Only 1 failure

try {
    $cb1->execute(fn() => throw new Exception("Fail 2"));
} catch (Exception $e) {
}

// Now it should be OPEN for BOTH because they share the store
assert($cb1->getState()->name === 'OPEN');
assert($cb2->getState()->name === 'OPEN');
echo "Distributed Circuit Breaker OK!\n";

echo "--- All Tests Passed! ---\n";
