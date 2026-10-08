<?php

declare(strict_types=1);

namespace Tusk\Cloud\Resilience\Exception;

use RuntimeException;

final class BulkheadRejectedException extends RuntimeException {}
