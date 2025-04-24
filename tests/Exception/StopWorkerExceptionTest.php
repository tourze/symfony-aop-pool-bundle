<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Exception;

use PHPUnit\Framework\TestCase;
use Tourze\BacktraceHelper\ContextAwareTrait;
use Tourze\Symfony\AopPoolBundle\Exception\StopWorkerException;

class StopWorkerExceptionTest extends TestCase
{
    public function testExceptionWithContext(): void
    {
        $context = ['serviceId' => 'test.service', 'contextId' => 'test-context'];
        $exception = new StopWorkerException('Test exception', 0, null, $context);

        $this->assertEquals('Test exception', $exception->getMessage());
        $this->assertEquals($context, $exception->getContext());
    }
    
    public function testExceptionWithoutContext(): void
    {
        $exception = new StopWorkerException('Test exception without context');
        
        $this->assertEquals('Test exception without context', $exception->getMessage());
        $this->assertEquals([], $exception->getContext());
    }
    
    public function testExceptionWithPreviousException(): void
    {
        $previousException = new \RuntimeException('Previous exception');
        $context = ['error' => 'connection_error'];
        $exception = new StopWorkerException('Worker exception', 0, $previousException, $context);
        
        $this->assertEquals('Worker exception', $exception->getMessage());
        $this->assertSame($previousException, $exception->getPrevious());
        $this->assertEquals($context, $exception->getContext());
    }
    
    public function testExceptionUsesContextAwareTrait(): void
    {
        $usedTraits = class_uses(StopWorkerException::class);
        $this->assertArrayHasKey(ContextAwareTrait::class, $usedTraits);
    }
    
    public function testExceptionWithCustomCode(): void
    {
        $code = 1001;
        $exception = new StopWorkerException('Custom code exception', $code);
        
        $this->assertEquals($code, $exception->getCode());
    }
}
