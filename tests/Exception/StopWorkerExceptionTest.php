<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\BacktraceHelper\ContextAwareTrait;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\Symfony\AopPoolBundle\Exception\StopWorkerException;

/**
 * @internal
 */
#[CoversClass(StopWorkerException::class)]
final class StopWorkerExceptionTest extends AbstractExceptionTestCase
{
    public function testExceptionWithContext(): void
    {
        $context = ['serviceId' => 'test.service', 'contextId' => 'test-context'];
        $exception = new StopWorkerException('Test exception', 0, null, $context);

        self::assertEquals('Test exception', $exception->getMessage());
        self::assertEquals($context, $exception->getContext());
    }

    public function testExceptionWithoutContext(): void
    {
        $exception = new StopWorkerException('Test exception without context');

        self::assertEquals('Test exception without context', $exception->getMessage());
        self::assertEquals([], $exception->getContext());
    }

    public function testExceptionWithPreviousException(): void
    {
        $previousException = new \RuntimeException('Previous exception');
        $context = ['error' => 'connection_error'];
        $exception = new StopWorkerException('Worker exception', 0, $previousException, $context);

        self::assertEquals('Worker exception', $exception->getMessage());
        self::assertSame($previousException, $exception->getPrevious());
        self::assertEquals($context, $exception->getContext());
    }

    public function testExceptionUsesContextAwareTrait(): void
    {
        $usedTraits = class_uses(StopWorkerException::class);
        self::assertArrayHasKey(ContextAwareTrait::class, $usedTraits);
    }

    public function testExceptionWithCustomCode(): void
    {
        $code = 1001;
        $exception = new StopWorkerException('Custom code exception', $code);

        self::assertEquals($code, $exception->getCode());
    }
}
