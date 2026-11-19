<?php

namespace App\Services\Assistant;

use RuntimeException;

/** The AI language model service did not respond in time (SRS §3.3.3). */
class AiServiceUnavailable extends RuntimeException {}
