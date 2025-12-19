<?php

declare(strict_types=1);

namespace Tourze\Symfony\AopPoolBundle\Tests\Aspect;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\Aop\Model\JoinPoint;
use Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect;
use Tourze\Symfony\AopPoolBundle\Exception\ConnectionUnhealthyException;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionLifecycleHandler;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager;
use Tourze\Symfony\RuntimeContextBundle\Service\ContextServiceInterface;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

/**
 * @internal
 */
#[CoversClass(ConnectionPoolAspect::class)]
#[RunTestsInSeparateProcesses]
final class ConnectionPoolAspectTest extends AbstractIntegrationTestCase
{
    protected ConnectionPoolAspect $aspect;

    protected ContextServiceInterface $contextService;

    protected ConnectionPoolManager $poolManager;

    protected ConnectionLifecycleHandler $lifecycleHandler;

    protected LoggerInterface $logger;

    protected function onSetUp(): void
    {
        // 从容器获取真实服务
        $this->aspect = self::getService(ConnectionPoolAspect::class);
        $this->poolManager = self::getService(ConnectionPoolManager::class);
        $this->lifecycleHandler = self::getService(ConnectionLifecycleHandler::class);
        $this->contextService = self::getService(ContextServiceInterface::class);
        $this->logger = self::getService(LoggerInterface::class);

        // 重置 ENV 设置
        $_ENV['SERVICE_POOL_GET_RETRY_ATTEMPTS'] = '5'; // 使用合理的重试次数
    }

    private function setPrivateProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass(get_class($object));
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function getPrivateProperty(object $object, string $propertyName): mixed
    {
        $reflection = new \ReflectionClass(get_class($object));
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    public function testGetRetryAttempts(): void
    {
        // 设置环境变量
        $_ENV['SERVICE_POOL_GET_RETRY_ATTEMPTS'] = '10';

        // 测试方法返回值是否正确
        $reflection = new \ReflectionMethod($this->aspect, 'getRetryAttempts');
        $reflection->setAccessible(true);
        self::assertEquals(10, $reflection->invoke($this->aspect));

        // 未设置环境变量时的默认值测试
        unset($_ENV['SERVICE_POOL_GET_RETRY_ATTEMPTS']);
        self::assertEquals(5, $reflection->invoke($this->aspect));
    }

    public function testRedisMethodHandlesDestruct(): void
    {
        // 创建 JoinPoint Mock - 第三方库，仍然使用 Mock
        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint->expects($this->any())->method('getMethod')->willReturn('__destruct');

        // 期望 setReturnEarly 方法会被调用一次
        $joinPoint->expects($this->once())
            ->method('setReturnEarly')
            ->with(true)
        ;

        // 期望 setReturnValue 方法会被调用一次
        $joinPoint->expects($this->once())
            ->method('setReturnValue')
            ->with(null)
        ;

        // 调用 redis 方法
        $this->aspect->redis($joinPoint);
    }

    public function testRedisMethodCallsPool(): void
    {
        // 创建 JoinPoint Mock - 第三方库
        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint->expects($this->any())->method('getMethod')->willReturn('get');

        // 设置服务ID
        $serviceId = 'test.service';
        $joinPoint->expects($this->any())->method('getInternalServiceId')->willReturn($serviceId);

        // 创建 Pool Mock - 第三方库
        $pool = $this->createMock(Pool::class);
        $pool->expects($this->any())->method('count')->willReturn(10);

        // 创建 Connection Mock - 第三方库
        $connection = $this->createMock(Connection::class);
        $resource = $this->createMock(\Redis::class);
        $connection->expects($this->any())->method('getResource')->willReturn($resource);

        // 模拟 pop() 返回连接
        $pool->expects($this->once())
            ->method('pop')
            ->willReturn($connection);

        // 注入模拟的 Pool 到 PoolManager（使用反射）
        $reflection = new \ReflectionClass($this->poolManager);
        $poolsProperty = $reflection->getProperty('pools');
        $poolsProperty->setAccessible(true);
        $poolsProperty->setValue($this->poolManager, [$serviceId => $pool]);

        // 初始化统计信息
        $statsProperty = $reflection->getProperty('poolStats');
        $statsProperty->setAccessible(true);
        $statsProperty->setValue($this->poolManager, [
            $serviceId => [
                'borrowed' => 0,
                'available' => 10,
                'total' => 10,
                'created' => 0,
                'destroyed' => 0,
            ],
        ]);

        // 期望 setInstance 会被调用
        $joinPoint->expects($this->once())
            ->method('setInstance')
            ->with($resource)
        ;

        // 调用 redis 方法 - 使用真实的 poolManager 和 lifecycleHandler
        $this->aspect->redis($joinPoint);

        // 验证连接已被借出
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        $contextId = $this->contextService->getId();
        self::assertArrayHasKey($contextId, $borrowedConnections);
        self::assertArrayHasKey($serviceId, $borrowedConnections[$contextId]);
        self::assertSame($connection, $borrowedConnections[$contextId][$serviceId]);
    }

    public function testResetWithNoBorrowedConnections(): void
    {
        // 调用 reset 方法 - 使用真实服务，无需 Mock
        $this->aspect->reset();

        // 验证没有借出的连接
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        $contextId = $this->contextService->getId();
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }

    public function testResetWithBorrowedConnections(): void
    {
        // 获取上下文ID
        $contextId = $this->contextService->getId();

        // 创建连接 Mock - 第三方库
        $connection = $this->createMock(Connection::class);
        $pool = $this->createMock(Pool::class);

        // 设置服务ID
        $serviceId = 'test.service';

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection,
            ],
        ]);

        // 注入模拟的 Pool 到 PoolManager
        $reflection = new \ReflectionClass($this->poolManager);
        $poolsProperty = $reflection->getProperty('pools');
        $poolsProperty->setAccessible(true);
        $poolsProperty->setValue($this->poolManager, [$serviceId => $pool]);

        // 初始化统计信息
        $statsProperty = $reflection->getProperty('poolStats');
        $statsProperty->setAccessible(true);
        $statsProperty->setValue($this->poolManager, [
            $serviceId => [
                'borrowed' => 1,
                'available' => 9,
                'total' => 10,
                'created' => 0,
                'destroyed' => 0,
            ],
        ]);

        // 期望 push 会被调用（returnConnection 内部调用）
        $pool->expects($this->once())
            ->method('push')
            ->with($connection);

        // 调用 reset 方法 - 使用真实的 lifecycleHandler
        $this->aspect->reset();

        // 验证 borrowedConnections 是否已清除
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }

    public function testResetWithUnhealthyConnection(): void
    {
        // 获取上下文ID
        $contextId = $this->contextService->getId();

        // 创建连接 Mock - 第三方库，模拟不健康的连接
        $connection = $this->createMock(Connection::class);
        $pool = $this->createMock(Pool::class);

        // 设置服务ID
        $serviceId = 'test.service';

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection,
            ],
        ]);

        // 注入模拟的 Pool 到 PoolManager
        $reflection = new \ReflectionClass($this->poolManager);
        $poolsProperty = $reflection->getProperty('pools');
        $poolsProperty->setAccessible(true);
        $poolsProperty->setValue($this->poolManager, [$serviceId => $pool]);

        // 初始化统计信息
        $statsProperty = $reflection->getProperty('poolStats');
        $statsProperty->setAccessible(true);
        $statsProperty->setValue($this->poolManager, [
            $serviceId => [
                'borrowed' => 1,
                'available' => 9,
                'total' => 10,
                'created' => 0,
                'destroyed' => 0,
            ],
        ]);

        // 让连接看起来已过期（通过设置很早的创建时间）
        $lifecycleReflection = new \ReflectionClass($this->lifecycleHandler);
        $startTimesProperty = $lifecycleReflection->getProperty('connectionStartTimes');
        $startTimesProperty->setAccessible(true);
        $connectionId = $this->lifecycleHandler->getConnectionId($connection);
        $startTimesProperty->setValue($this->lifecycleHandler, [
            $connectionId => time() - 1000, // 1000秒前创建，超过默认的60秒生命周期
        ]);

        // 期望 destroy 会被调用（destroyConnection 内部调用）
        $pool->expects($this->once())
            ->method('destroy')
            ->with($connection);

        // 调用 reset 方法 - 使用真实的 lifecycleHandler 检测过期
        $this->aspect->reset();

        // 验证 borrowedConnections 是否已清除
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }

    /**
     * 测试 pool 方法的基本功能
     */
    public function testPool(): void
    {
        // 创建 JoinPoint Mock - 第三方库
        $joinPoint = $this->createMock(JoinPoint::class);
        $serviceId = 'test.service2';
        $joinPoint->expects($this->any())->method('getInternalServiceId')->willReturn($serviceId);

        // 创建 Pool 和 Connection Mock - 第三方库
        $pool = $this->createMock(Pool::class);
        $pool->expects($this->any())->method('count')->willReturn(10);
        $connection = $this->createMock(Connection::class);
        $resource = $this->createMock(\Redis::class);
        $connection->expects($this->any())->method('getResource')->willReturn($resource);

        // 模拟 pop() 返回连接
        $pool->expects($this->once())
            ->method('pop')
            ->willReturn($connection);

        // 注入模拟的 Pool 到 PoolManager
        $reflection = new \ReflectionClass($this->poolManager);
        $poolsProperty = $reflection->getProperty('pools');
        $poolsProperty->setAccessible(true);
        $poolsProperty->setValue($this->poolManager, [$serviceId => $pool]);

        // 初始化统计信息
        $statsProperty = $reflection->getProperty('poolStats');
        $statsProperty->setAccessible(true);
        $statsProperty->setValue($this->poolManager, [
            $serviceId => [
                'borrowed' => 0,
                'available' => 10,
                'total' => 10,
                'created' => 0,
                'destroyed' => 0,
            ],
        ]);

        // 期望 setInstance 会被调用
        $joinPoint->expects($this->once())
            ->method('setInstance')
            ->with($resource)
        ;

        // 调用 pool 方法 - 使用真实服务
        $this->aspect->pool($joinPoint);

        // 验证连接已被借出
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        $contextId = $this->contextService->getId();
        self::assertArrayHasKey($contextId, $borrowedConnections);
        self::assertArrayHasKey($serviceId, $borrowedConnections[$contextId]);
    }

    /**
     * 测试 returnAll 方法的基本功能
     */
    public function testReturnAll(): void
    {
        // 获取上下文ID
        $contextId = $this->contextService->getId();

        // 创建连接和池的 Mock - 第三方库
        $connection = $this->createMock(Connection::class);
        $pool = $this->createMock(Pool::class);
        $serviceId = 'test.service3';

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection,
            ],
        ]);

        // 注入模拟的 Pool 到 PoolManager
        $reflection = new \ReflectionClass($this->poolManager);
        $poolsProperty = $reflection->getProperty('pools');
        $poolsProperty->setAccessible(true);
        $poolsProperty->setValue($this->poolManager, [$serviceId => $pool]);

        // 初始化统计信息
        $statsProperty = $reflection->getProperty('poolStats');
        $statsProperty->setAccessible(true);
        $statsProperty->setValue($this->poolManager, [
            $serviceId => [
                'borrowed' => 1,
                'available' => 9,
                'total' => 10,
                'created' => 0,
                'destroyed' => 0,
            ],
        ]);

        // 期望 push 会被调用
        $pool->expects($this->once())
            ->method('push')
            ->with($connection);

        // 调用 returnAll 方法 - 使用真实服务
        $this->aspect->returnAll();

        // 验证 borrowedConnections 是否已清除
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }
}
