<?php

namespace Tusk\Cloud\Resilience;

enum State: string
{
    case CLOSED = 'CLOSED';   // Normal operation
    case OPEN = 'OPEN';       // Failing, reject requests
    case HALF_OPEN = 'HALF_OPEN'; // Testing if system recovered
}
