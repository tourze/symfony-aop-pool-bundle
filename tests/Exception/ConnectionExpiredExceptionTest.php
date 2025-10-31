<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\Symfony\AopPoolBundle\Exception\ConnectionExpiredException;

/**
 * @internal
 */
#[CoversClass(ConnectionExpiredException::class)]
final class ConnectionExpiredExceptionTest extends AbstractExceptionTestCase
{
    public function testConnectionExpiredExceptionExtendsException(): void
    {
        $exception = new ConnectionExpiredException('连接已过期');

        self::assertEquals('连接已过期', $exception->getMessage());
    }

    public function testConnectionExpiredExceptionWithCodeAndPrevious(): void
    {
        $previous = new \RuntimeException('原始异常');
        $exception = new ConnectionExpiredException('连接已过期', 500, $previous);

        self::assertEquals('连接已过期', $exception->getMessage());
        self::assertEquals(500, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
