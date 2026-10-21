<?php

declare(strict_types=1);

namespace VastsverigeEvents\Api;

/**
 * Raised when the Västsverige API cannot be reached or answers with an unusable payload.
 */
class ApiException extends \RuntimeException
{
}
