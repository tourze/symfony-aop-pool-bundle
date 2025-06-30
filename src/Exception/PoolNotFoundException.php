<?php

namespace Tourze\Symfony\AopPoolBundle\Exception;

use Tourze\BacktraceHelper\ContextAwareTrait;

/**
 * 连接池未找到异常
 */
class PoolNotFoundException extends \RuntimeException
{
    use ContextAwareTrait;
}