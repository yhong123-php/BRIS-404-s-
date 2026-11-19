<?php

namespace App\Services\Assistant;

use RuntimeException;

/** Raised when the model asks for a function or argument outside the whitelist (SDD §4.22 step 4). */
class InvalidQueryArguments extends RuntimeException {}
