<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Aspect;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Tourze\Symfony\Aop\Model\JoinPoint;
use Tourze\Symfony\Aop\Service\InstanceService;
use Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect;
use Tourze\Symfony\RuntimeContextBundle\Service\ContextServiceInterface;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

class ConnectionPoolAspectTest extends TestCase
{
    protected $aspect;
    protected $contextService;
    protected $instanceService;
    protected $kernel;

    protected function setUp(): void
    {
        parent::setUp();

        // 模拟 ContextServiceInterface
        $this->contextService = $this->createMock(ContextServiceInterface::class);

        // 模拟 InstanceService
        $this->instanceService = $this->createMock(InstanceService::class);

        // 模拟 KernelInterface
        $this->kernel = $this->createMock(KernelInterface::class);

        // 创建 ConnectionPoolAspect 实例
        $this->aspect = new ConnectionPoolAspect(
            $this->contextService,
            $this->instanceService,
            $this->kernel
        );

        // 重置 ENV 设置
        $_ENV['SERVICE_POOL_DEFAULT_SIZE'] = '10'; // 使用较小的池大小便于测试
        $_ENV['DEBUG_ConnectionPoolAspect'] = false;

        // 重置静态属性
        $this->resetStaticPools();
    }

    protected function tearDown(): void
    {
        // 重置静态属性
        $this->resetStaticPools();

        parent::tearDown();
    }

    private function resetStaticPools(): void
    {
        $reflectionClass = new \ReflectionClass(ConnectionPoolAspect::class);
        $poolsProperty = $reflectionClass->getProperty('pools');
        $poolsProperty->setAccessible(true);
        $poolsProperty->setValue(null, []);
    }

    private function invokePrivateMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }

    private function setPrivateProperty($object, string $propertyName, $value)
    {
        $reflection = new \ReflectionClass(get_class($object));
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function getPrivateProperty($object, string $propertyName)
    {
        $reflection = new \ReflectionClass(get_class($object));
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($object);
    }

    public function testGetPoolMaxSize(): void
    {
        // 设置环境变量
        $_ENV['SERVICE_POOL_DEFAULT_SIZE'] = '100';

        // 测试方法返回值是否正确
        $this->assertEquals(100, $this->invokePrivateMethod($this->aspect, 'getPoolMaxSize'));

        // 未设置环境变量时的默认值测试
        unset($_ENV['SERVICE_POOL_DEFAULT_SIZE']);
        $this->assertEquals(500, $this->invokePrivateMethod($this->aspect, 'getPoolMaxSize'));
    }

    public function testGetObjectId(): void
    {
        $object = new \stdClass();

        // 获取对象ID
        $objectId = $this->invokePrivateMethod($this->aspect, 'getObjectId', [$object]);

        // 验证返回值是否与 spl_object_hash 相同
        $this->assertEquals(spl_object_hash($object), $objectId);
    }

    public function testCheckConnectionWithRedis(): void
    {
        // 跳过测试如果 Redis 类不存在
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('Redis class not found');
        }

        // 创建 Redis 连接 Mock
        $redis = $this->createMock(\Redis::class);
        $connection = $this->createMock(Connection::class);
        $connection->method('getResource')->willReturn($redis);

        // 设置私有属性 connStartTimes 为空
        $this->setPrivateProperty($this->aspect, 'connStartTimes', []);

        // 调用 checkConnection 方法
        $this->invokePrivateMethod($this->aspect, 'checkConnection', [$connection]);

        // 验证 connStartTimes 是否有记录
        $connStartTimes = $this->getPrivateProperty($this->aspect, 'connStartTimes');
        $objectId = $this->invokePrivateMethod($this->aspect, 'getObjectId', [$connection]);
        $this->assertArrayHasKey($objectId, $connStartTimes);

        // 测试连接老化检测
        // 设置连接开始时间为一小时之前
        $this->setPrivateProperty($this->aspect, 'connStartTimes', [
            $objectId => time() - 3600
        ]);

        // 期望抛出异常
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Redis对象过老');
        $this->invokePrivateMethod($this->aspect, 'checkConnection', [$connection]);
    }

    public function testCheckConnectionWithDbal(): void
    {
        // 跳过测试如果 Doctrine\DBAL\Connection 类不存在
        if (!class_exists(\Doctrine\DBAL\Connection::class)) {
            $this->markTestSkipped('Doctrine\DBAL\Connection class not found');
        }

        // 创建 DBAL 连接 Mock
        $dbal = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection = $this->createMock(Connection::class);
        $connection->method('getResource')->willReturn($dbal);

        // 设置私有属性 connStartTimes 为空
        $this->setPrivateProperty($this->aspect, 'connStartTimes', []);

        // 调用 checkConnection 方法
        $this->invokePrivateMethod($this->aspect, 'checkConnection', [$connection]);

        // 验证 connStartTimes 是否有记录
        $connStartTimes = $this->getPrivateProperty($this->aspect, 'connStartTimes');
        $objectId = $this->invokePrivateMethod($this->aspect, 'getObjectId', [$connection]);
        $this->assertArrayHasKey($objectId, $connStartTimes);

        // 测试连接老化检测
        // 设置连接开始时间为一小时之前
        $this->setPrivateProperty($this->aspect, 'connStartTimes', [
            $objectId => time() - 3600
        ]);

        // 期望抛出异常
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('PDO对象过老');
        $this->invokePrivateMethod($this->aspect, 'checkConnection', [$connection]);
    }

    public function testDestroyConnectionWithRedis(): void
    {
        // 跳过测试如果 Redis 类不存在
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('Redis class not found');
        }

        // 创建 Pool Mock
        $pool = $this->createMock(Pool::class);

        // 期望 destroy 方法会被调用一次
        $pool->expects($this->once())
            ->method('destroy');

        // 测试 Redis 连接销毁
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('close');

        $connection = $this->createMock(Connection::class);
        $connection->method('getResource')->willReturn($redis);

        // 记录 connStartTimes
        $objectId = $this->invokePrivateMethod($this->aspect, 'getObjectId', [$connection]);
        $this->setPrivateProperty($this->aspect, 'connStartTimes', [
            $objectId => time()
        ]);

        // 调用 destroyConnection 方法
        $this->invokePrivateMethod($this->aspect, 'destroyConnection', [$pool, $connection]);

        // 验证 connStartTimes 是否已清理
        $connStartTimes = $this->getPrivateProperty($this->aspect, 'connStartTimes');
        $this->assertArrayNotHasKey($objectId, $connStartTimes);
    }

    public function testDestroyConnectionWithDbal(): void
    {
        // 跳过测试如果 Doctrine\DBAL\Connection 类不存在
        if (!class_exists(\Doctrine\DBAL\Connection::class)) {
            $this->markTestSkipped('Doctrine\DBAL\Connection class not found');
        }

        // 创建 Pool Mock
        $pool = $this->createMock(Pool::class);

        // 期望 destroy 方法会被调用一次
        $pool->expects($this->once())
            ->method('destroy');

        // 测试 DBAL 连接销毁
        $dbal = $this->createMock(\Doctrine\DBAL\Connection::class);
        $dbal->expects($this->once())
            ->method('close');

        $connection = $this->createMock(Connection::class);
        $connection->method('getResource')->willReturn($dbal);

        // 记录 connStartTimes
        $objectId = $this->invokePrivateMethod($this->aspect, 'getObjectId', [$connection]);
        $this->setPrivateProperty($this->aspect, 'connStartTimes', [
            $objectId => time()
        ]);

        // 调用 destroyConnection 方法
        $this->invokePrivateMethod($this->aspect, 'destroyConnection', [$pool, $connection]);

        // 验证 connStartTimes 是否已清理
        $connStartTimes = $this->getPrivateProperty($this->aspect, 'connStartTimes');
        $this->assertArrayNotHasKey($objectId, $connStartTimes);
    }

    public function testRedisMethodHandlesDestruct(): void
    {
        // 创建 JoinPoint Mock
        $joinPoint = $this->createMock(JoinPoint::class);

        // 设置 __destruct 方法
        $joinPoint->method('getMethod')->willReturn('__destruct');

        // 期望 setReturnEarly 和 setReturnValue 被调用
        $joinPoint->expects($this->once())
            ->method('setReturnEarly')
            ->with(true);

        $joinPoint->expects($this->once())
            ->method('setReturnValue')
            ->with(null);

        // 期望 pool 方法不会被调用
        $aspect = $this->getMockBuilder(ConnectionPoolAspect::class)
            ->setConstructorArgs([
                $this->contextService,
                $this->instanceService,
                $this->kernel
            ])
            ->onlyMethods(['pool'])
            ->getMock();

        $aspect->expects($this->never())
            ->method('pool');

        // 调用 redis 方法
        $aspect->redis($joinPoint);
    }

    public function testRedisMethodCallsPool(): void
    {
        // 创建 JoinPoint Mock
        $joinPoint = $this->createMock(JoinPoint::class);

        // 设置非 __destruct 方法
        $joinPoint->method('getMethod')->willReturn('get');

        // 期望 pool 方法会被调用
        $aspect = $this->getMockBuilder(ConnectionPoolAspect::class)
            ->setConstructorArgs([
                $this->contextService,
                $this->instanceService,
                $this->kernel
            ])
            ->onlyMethods(['pool'])
            ->getMock();

        $aspect->expects($this->once())
            ->method('pool')
            ->with($joinPoint);

        // 调用 redis 方法
        $aspect->redis($joinPoint);
    }

    public function testGetSubscribedEvents(): void
    {
        $events = ConnectionPoolAspect::getSubscribedEvents();

        $this->assertIsArray($events);
        $this->assertArrayHasKey(KernelEvents::TERMINATE, $events);
        $this->assertArrayHasKey(ConsoleEvents::TERMINATE, $events);
    }
}
