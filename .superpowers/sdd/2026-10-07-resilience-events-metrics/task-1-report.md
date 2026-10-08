# Task 1 report: immutable resilience event contracts

## TDD evidence

### RED

Command:

```text
vendor\bin\phpunit tusk-cloud\tests\Resilience\Event\ResilienceEventTest.php
```

Output before implementation:

```text
EEEEFF                                                              6 / 6 (100%)

There were 4 errors:
1) test_rejection_reasons_have_the_exact_wire_values
   Error: Class "Tusk\Cloud\Resilience\Event\OperationRejectionReason" not found
2) test_event_payloads_are_public_readonly_values
   Error: Class "Tusk\Cloud\Resilience\Event\RetryScheduled" not found
3) test_retry_and_fallback_reject_blank_operation_or_failure_type
   Error: Class "Tusk\Cloud\Resilience\Event\RetryScheduled" not found
4) test_retry_rejects_attempts_below_one_and_negative_delays
   Error: Class "Tusk\Cloud\Resilience\Event\RetryScheduled" not found

There were 2 failures:
1) test_events_reject_blank_operation_names
   Expected InvalidArgumentException; OperationRejected was not found.
2) test_circuit_state_change_rejects_the_same_state
   Expected InvalidArgumentException; CircuitStateChanged was not found.

FAILURES!
Tests: 6, Assertions: 2, Failures: 2, Errors: 4.
```

The failures were due to the missing event contracts. Before implementation, review against the brief removed the extra blank-operation assertion for `OperationRejected`, because that constructor has no such validation requirement. The final focused test has five cases.

### GREEN and requested checks

Focused command:

```text
vendor\bin\phpunit tusk-cloud\tests\Resilience\Event\ResilienceEventTest.php
```

Output:

```text
.....                                                               5 / 5 (100%)
OK (5 tests, 21 assertions)
```

Style command:

```text
vendor\bin\pint --test tusk-cloud\src\Resilience\Event tusk-cloud\tests\Resilience\Event\ResilienceEventTest.php
```

Output: `{"tool":"pint","result":"passed"}`

Static analysis command:

```text
vendor\bin\phpstan analyse tusk-cloud\src\Resilience\Event tusk-cloud\tests\Resilience\Event\ResilienceEventTest.php --no-progress
```

Output: `[OK] No errors`

## Changed files

- `tusk-cloud/src/Resilience/Event/OperationRejectionReason.php`
- `tusk-cloud/src/Resilience/Event/RetryScheduled.php`
- `tusk-cloud/src/Resilience/Event/FallbackApplied.php`
- `tusk-cloud/src/Resilience/Event/OperationRejected.php`
- `tusk-cloud/src/Resilience/Event/CircuitStateChanged.php`
- `tusk-cloud/tests/Resilience/Event/ResilienceEventTest.php`
- `.superpowers/sdd/2026-10-07-resilience-events-metrics/task-1-report.md`

## Self-review

- The enum case names and string values match the brief exactly.
- All four event classes are `final readonly` with public readonly promoted payloads named as specified.
- Validation is limited to nonblank retry/fallback operation and failure type, retry attempt and delay bounds, and differing circuit states.
- Payloads contain no metadata, throwable, message, URL, or request/response values.
- `git diff --check` reported no whitespace errors. The generated PHPUnit cache result was removed; no unrelated local setup files are included.

## Concerns

None identified within Task 1 scope.
