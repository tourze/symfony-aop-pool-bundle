<?php

namespace Tourze\Symfony\AopPoolBundle\Exception;

use Tourze\BacktraceHelper\ContextAwareTrait;

/**
 * 连接不健康异常
 */
class ConnectionUnhealthyException extends \Exception
{
    use ContextAwareTrait;
}