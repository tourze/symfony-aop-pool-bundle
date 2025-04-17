<?php

namespace Tourze\Symfony\AopPoolBundle\Attribute;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * 标记使用连接池
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ConnectionPool extends AutoconfigureTag
{
    public function __construct()
    {
        parent::__construct('connection-pool-service');
    }
}
