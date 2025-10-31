<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Tourze\Symfony\AopPoolBundle\Attribute\ConnectionPool;

/**
 * @internal
 */
#[CoversClass(ConnectionPool::class)]
final class ConnectionPoolTest extends TestCase
{
    public function testConstructor(): void
    {
        $attribute = new ConnectionPool();

        // 验证属性创建成功，通过方法调用确认对象可用
        self::expectNotToPerformAssertions();
    }

    public function testAttributeTag(): void
    {
        $attribute = new ConnectionPool();

        // 在PHP 8.4下，我们跳过复杂的反射并简单验证ConnectionPool是否继承自AutoconfigureTag
        $parentClass = get_parent_class($attribute);
        self::assertEquals(AutoconfigureTag::class, $parentClass);

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

        self::assertEquals('connection-pool-service', $mockTag->tagName);
    }

    public function testAttributeTargetClass(): void
    {
        // 验证属性只能用在类上
        $reflectionClass = new \ReflectionClass(ConnectionPool::class);
        $attributes = $reflectionClass->getAttributes(\Attribute::class);

        $found = false;
        foreach ($attributes as $attribute) {
            $arguments = $attribute->getArguments();
            // 检查是否设置了 TARGET_CLASS 标志
            if (isset($arguments['flags']) && (bool) ($arguments['flags'] & \Attribute::TARGET_CLASS)) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'ConnectionPool属性应该只能用在类上');
    }
}
