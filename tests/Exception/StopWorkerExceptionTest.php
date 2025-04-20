<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Exception;

use PHPUnit\Framework\TestCase;
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
}
