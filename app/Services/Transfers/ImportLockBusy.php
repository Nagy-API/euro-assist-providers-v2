<?php

namespace App\Services\Transfers;

use RuntimeException;

/** Another import currently holds the global apply lock; the job must be released back to the queue. */
class ImportLockBusy extends RuntimeException {}
