<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The server just failed to connect and is not tried again for a short while.
 * Retrying it at once only stacks timeouts on the page that is waiting.
 */
class ServerUnreachableException extends RuntimeException {}
