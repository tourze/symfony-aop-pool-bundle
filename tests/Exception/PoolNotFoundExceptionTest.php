<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\Symfony\AopPoolBundle\Exception\PoolNotFoundException;

/**
 * @internal
 */
#[CoversClass(PoolNotFoundException::class)]
final class PoolNotFoundExceptionTest extends AbstractExceptionTestCase
{
    public function testPoolNotFoundExceptionExtendsRuntimeException(): void
    {
        $exception = new PoolNotFoundException('连接池未找到');

        self::assertEquals('连接池未找到', $exception->getMessage());
    }

    public function testPoolNotFoundExceptionWithCodeAndPrevious(): void
    {
        $previous = new \Exception('原始异常');
        $exception = new PoolNotFoundException('连接池未找到', 404, $previous);

        self::assertEquals('连接池未找到', $exception->getMessage());
        self::assertEquals(404, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
