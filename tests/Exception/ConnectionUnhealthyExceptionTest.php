<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\Symfony\AopPoolBundle\Exception\ConnectionUnhealthyException;

/**
 * @internal
 */
#[CoversClass(ConnectionUnhealthyException::class)]
final class ConnectionUnhealthyExceptionTest extends AbstractExceptionTestCase
{
    public function testConnectionUnhealthyExceptionExtendsException(): void
    {
        $exception = new ConnectionUnhealthyException('连接不健康');

        self::assertEquals('连接不健康', $exception->getMessage());
    }

    public function testConnectionUnhealthyExceptionWithCodeAndPrevious(): void
    {
        $previous = new \RuntimeException('原始异常');
        $exception = new ConnectionUnhealthyException('连接不健康', 500, $previous);

        self::assertEquals('连接不健康', $exception->getMessage());
        self::assertEquals(500, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
