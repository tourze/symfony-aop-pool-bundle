<?php

declare(strict_types=1);

namespace Tourze\Symfony\AopPoolBundle\Exception;

use Tourze\BacktraceHelper\ContextAwareTrait;

/**
 * 连接过期异常
 */
final class ConnectionExpiredException extends \Exception
{
    use ContextAwareTrait;
}
