<?php

namespace Ledric\Laravel\Exceptions;

// Thrown when ledric is unreachable (connection refused, timeout, 5xx).
// Distinct from LedricException so callers can fall back to cached values
// without swallowing genuine 4xx programming errors.
class LedricUnavailableException extends LedricException
{
}
