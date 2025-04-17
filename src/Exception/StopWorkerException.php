<?php

namespace Tourze\Symfony\AopPoolBundle\Exception;

use Tourze\BacktraceHelper\ContextAwareTrait;

/**
 * 接受到这个异常，应该尝试 Worker::stopAll();
 *
 * @see https://www.workerman.net/doc/workerman/faq/max-requests.html
 */
class StopWorkerException extends \Exception
{
    use ContextAwareTrait;
}
