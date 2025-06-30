<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\AopPoolBundle\Exception\ConnectionUnhealthyException;

class ConnectionUnhealthyExceptionTest extends TestCase
{
    public function testConnectionUnhealthyExceptionExtendsException(): void
    {
        $exception = new ConnectionUnhealthyException('连接不健康');
        
        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertEquals('连接不健康', $exception->getMessage());
    }

    public function testConnectionUnhealthyExceptionWithCodeAndPrevious(): void
    {
        $previous = new \RuntimeException('原始异常');
        $exception = new ConnectionUnhealthyException('连接不健康', 500, $previous);
        
        $this->assertEquals('连接不健康', $exception->getMessage());
        $this->assertEquals(500, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}