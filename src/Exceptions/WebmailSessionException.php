<?php

namespace Nawasara\Whm\Exceptions;

use RuntimeException;

/**
 * Thrown saat WHM API gagal forge session URL — misalnya credential
 * invalid, API down, atau response shape unexpected.
 */
class WebmailSessionException extends RuntimeException
{
}
