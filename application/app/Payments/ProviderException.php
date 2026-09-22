<?php

namespace App\Payments;

use RuntimeException;

// Only fixed technical codes; never attach transport exceptions or payloads.
class ProviderException extends RuntimeException {}
