<?php

declare(strict_types=1);

namespace Tourze\Symfony\AopPoolBundle\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractBundleTestCase;
use Tourze\Symfony\AopPoolBundle\AopPoolBundle;

/**
 * @internal
 */
#[CoversClass(AopPoolBundle::class)]
#[RunTestsInSeparateProcesses]
final class AopPoolBundleTest extends AbstractBundleTestCase
{
}
