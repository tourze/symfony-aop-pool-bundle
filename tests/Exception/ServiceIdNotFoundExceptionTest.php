<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\Symfony\AopPoolBundle\Exception\ServiceIdNotFoundException;

/**
 * @internal
 */
#[CoversClass(ServiceIdNotFoundException::class)]
final class ServiceIdNotFoundExceptionTest extends AbstractExceptionTestCase
{
    public function testServiceIdNotFoundExceptionExtendsRuntimeException(): void
    {
        $exception = new ServiceIdNotFoundException('Service not found');

        self::assertSame('Service not found', $exception->getMessage());
    }

    public function testServiceIdNotFoundExceptionWithCodeAndPrevious(): void
    {
        $previous = new \Exception('Previous exception');
        $exception = new ServiceIdNotFoundException('Service not found', 500, $previous);

        self::assertSame('Service not found', $exception->getMessage());
        self::assertSame(500, $exception->getCode());
        self::assertSame($previous, $exception->getPrevious());
    }
}
