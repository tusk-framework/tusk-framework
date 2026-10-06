# RoadRunner as the primary Tusk runtime

## Goal

Make RoadRunner the sole runtime for Tusk applications and align the Framework with the Engine-managed worker contract.

## Constraints

- Preserve `RuntimeAdapterInterface` and the existing PSR-7 request/response flow.
- Reject unsupported runtime selections with an actionable RoadRunner migration message.
- Do not reimplement RoadRunner's process pool or transport in Tusk.
- Never write human-readable startup output to stdout while attached to the RoadRunner worker protocol.
- Request-scope reset must run in `finally` for every request.

## Tasks

1. Add a runtime factory that defaults to `roadrunner` and rejects unknown or unsupported adapters.
2. Harden `RoadRunnerAdapter` lifecycle: retain the worker handle, stop the RR worker on shutdown, reset scope, collect cycles, and clear adapter state.
3. Make `RunCommand` select the adapter from `--runtime` or `TUSK_RUNTIME`, defaulting to RoadRunner, and keep protocol-safe logging.
4. Declare RoadRunner/PSR-7 dependencies in the runtime package and add a versioned `.rr.yaml.example` with worker recycling and memory limits.
5. Document the runtime contract and verify PHP syntax/tests when PHP and Composer are available; record environment blockers otherwise.

## Acceptance criteria

- `RuntimeAdapterFactory::create()` returns RoadRunner by default, and RoadRunner is the only supported runtime.
- `tusk run app.php` does not emit human-readable stdout before the RR protocol starts.
- `RoadRunnerAdapter::stop()` requests a real worker stop and does not leave the loop marked running.
- The example configuration uses RoadRunner v3 syntax and bounded worker lifecycle settings.
- Existing RoadRunner lifecycle tests and the Engine contract remain unchanged.
