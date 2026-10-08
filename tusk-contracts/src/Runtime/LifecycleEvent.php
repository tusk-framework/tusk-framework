<?php

namespace Tusk\Contracts\Runtime;

enum LifecycleEvent: string
{
    case APPLICATION_START = 'application.start';
    case WORKER_START = 'worker.start';
    case REQUEST_START = 'request.start';
    case REQUEST_END = 'request.end';
    case JOB_START = 'job.start';
    case JOB_END = 'job.end';
    case WORKER_STOP = 'worker.stop';
    case APPLICATION_STOP = 'application.stop';
}
