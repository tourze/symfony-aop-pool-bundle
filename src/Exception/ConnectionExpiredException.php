<?php

namespace Tourze\Symfony\AopPoolBundle\Exception;

use Tourze\BacktraceHelper\ContextAwareTrait;

/**
 * 连接过期异常
 */
class ConnectionExpiredException extends \Exception
{
    use ContextAwareTrait;
}