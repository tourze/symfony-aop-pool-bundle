<?php

declare(strict_types=1);

namespace Tourze\Symfony\AopPoolBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;
use Tourze\BundleDependency\BundleDependencyInterface;
use Tourze\Symfony\Aop\AopBundle as SymfonyAopBundle;
use Tourze\Symfony\RuntimeContextBundle\RuntimeContextBundle;

class AopPoolBundle extends Bundle implements BundleDependencyInterface
{
    public static function getBundleDependencies(): array
    {
        return [
            SymfonyAopBundle::class => ['all' => true],
            RuntimeContextBundle::class => ['all' => true],
        ];
    }
}
