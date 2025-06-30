<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\AopPoolBundle\Exception\ConnectionExpiredException;

class ConnectionExpiredExceptionTest extends TestCase
{
    public function testConnectionExpiredExceptionExtendsException(): void
    {
        $exception = new ConnectionExpiredException('连接已过期');
        
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertEquals('连接已过期', $exception->getMessage());
    }

    public function testConnectionExpiredExceptionWithCodeAndPrevious(): void
    {
        $previous = new \RuntimeException('原始异常');
        $exception = new ConnectionExpiredException('连接已过期', 500, $previous);
        
        $this->assertEquals('连接已过期', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}