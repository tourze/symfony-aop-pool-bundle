<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\AopPoolBundle\Attribute\ConnectionPool;

class ConnectionPoolTest extends TestCase
{
    public function testConstructor(): void
    {
        $attribute = new ConnectionPool();

        $this->assertInstanceOf(ConnectionPool::class, $attribute);
    }
}
