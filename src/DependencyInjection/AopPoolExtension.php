<?php

declare(strict_types=1);

namespace Tourze\Symfony\AopPoolBundle\DependencyInjection;

use Tourze\SymfonyDependencyServiceLoader\AutoExtension;

class AopPoolExtension extends AutoExtension
{
    protected function getConfigDir(): string
    {
        return __DIR__ . '/../Resources/config';
    }
}
