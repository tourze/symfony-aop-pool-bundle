<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\AopPoolBundle\Exception\PoolNotFoundException;

class PoolNotFoundExceptionTest extends TestCase
{
    public function testPoolNotFoundExceptionExtendsRuntimeException(): void
    {
        $exception = new PoolNotFoundException('连接池未找到');
        
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('连接池未找到', $exception->getMessage());
    }

    public function testPoolNotFoundExceptionWithCodeAndPrevious(): void
    {
        $previous = new \Exception('原始异常');
        $exception = new PoolNotFoundException('连接池未找到', 404, $previous);
        
        $this->assertEquals('连接池未找到', $exception->getMessage());
        $this->assertEquals(404, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}