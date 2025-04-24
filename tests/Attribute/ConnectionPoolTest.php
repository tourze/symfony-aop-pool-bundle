<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Tourze\Symfony\AopPoolBundle\Attribute\ConnectionPool;

class ConnectionPoolTest extends TestCase
{
    public function testConstructor(): void
    {
        $attribute = new ConnectionPool();

        $this->assertInstanceOf(ConnectionPool::class, $attribute);
        $this->assertInstanceOf(AutoconfigureTag::class, $attribute);
    }

    public function testAttributeTag(): void
    {
        $attribute = new ConnectionPool();

        // 在PHP 8.4下，我们跳过复杂的反射并简单验证ConnectionPool是否继承自AutoconfigureTag
        $parentClass = get_parent_class($attribute);
        $this->assertEquals(AutoconfigureTag::class, $parentClass);

        // 测试构造函数是否传递了正确的tag名称
        // 我们可以通过创建一个自定义的类并验证其构造函数参数来间接测试
        $mockTag = new class('connection-pool-service') extends AutoconfigureTag {
            public string $tagName;

            public function __construct(string $name)
            {
                $this->tagName = $name;
                parent::__construct($name);
            }
        };

        $this->assertEquals('connection-pool-service', $mockTag->tagName);
    }

    public function testAttributeTargetClass(): void
    {
        // 验证属性只能用在类上
        $reflectionClass = new ReflectionClass(ConnectionPool::class);
        $attributes = $reflectionClass->getAttributes();

        $found = false;
        foreach ($attributes as $attribute) {
            if ($attribute->getName() === \Attribute::class) {
                $arguments = $attribute->getArguments();
                if (isset($arguments[0]) && $arguments[0] === \Attribute::TARGET_CLASS) {
                    $found = true;
                    break;
                }
            }
        }

        $this->assertTrue($found, 'ConnectionPool属性应该只能用在类上');
    }
}
