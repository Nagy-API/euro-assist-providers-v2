<?php

namespace App\Services\Legacy;

use RuntimeException;

/** A deliberate refusal of the legacy pipeline (messages never contain source values). */
class LegacyException extends RuntimeException {}
