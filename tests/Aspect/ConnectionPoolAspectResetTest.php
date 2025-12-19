<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Aspect;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\Aop\Model\JoinPoint;
use Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionLifecycleHandler;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager;
use Tourze\Symfony\RuntimeContextBundle\Service\ContextServiceInterface;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;


#[CoversClass(ConnectionPoolAspect::class)]
#[RunTestsInSeparateProcesses]
final class ConnectionPoolAspectResetTest extends AbstractIntegrationTestCase
{
    private ConnectionPoolAspect $aspect;

    private ContextServiceInterface $contextService;

    private ConnectionPoolManager $poolManager;

    private ConnectionLifecycleHandler $lifecycleHandler;

    protected function onSetUp(): void
    {
        // 从容器获取真实服务实例
        $this->aspect = self::getService(ConnectionPoolAspect::class);
        $this->contextService = self::getService(ContextServiceInterface::class);
        $this->poolManager = self::getService(ConnectionPoolManager::class);
        $this->lifecycleHandler = self::getService(ConnectionLifecycleHandler::class);
    }

    private function setPrivateProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass($object::class);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    private function getPrivateProperty(object $object, string $propertyName): mixed
    {
        $reflection = new \ReflectionClass($object::class);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    /**
     * 测试 reset 方法在没有借出连接的情况下
     */
    public function testResetWithNoBorrowedConnections(): void
    {
        // 调用 reset 方法（使用真实服务，不设置任何 Mock 期望）
        $this->aspect->reset();

        // 验证借出的连接数组为空
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        $contextId = $this->contextService->getId();
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }

    /**
     * 测试 reset 方法正确归还健康的连接
     */
    public function testResetReturnsHealthyConnectionsToPool(): void
    {
        // 获取上下文ID
        $contextId = $this->contextService->getId();

        // 创建连接 Mock（第三方库 Utopia\Pools\Connection 没有接口，必须使用具体类）
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们只需要一个连接对象的占位符，不需要其具体行为
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        $connection = $this->createMock(Connection::class);
        /**
         * 必须使用具体类 Pool 而不是接口，因为：
         * 1. Pool 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 测试中需要模拟池的行为，而不是实现细节
         * 3. 这是合理的，因为我们无法控制第三方库的设计
         */
        $pool = $this->createMock(Pool::class);

        // 设置服务ID
        $serviceId = 'test.service';

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection,
            ],
        ]);

        // 模拟池管理器返回池
        $poolManagerReflection = new \ReflectionClass($this->poolManager);
        $poolsProperty = $poolManagerReflection->getProperty('pools');
        $poolsProperty->setAccessible(true);
        $poolsProperty->setValue($this->poolManager, [$serviceId => $pool]);

        // 模拟池的计数
        $pool->method('count')->willReturn(5);

        // 调用 reset 方法
        $this->aspect->reset();

        // 验证 borrowedConnections 是否已清除
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }

    /**
     * 测试 reset 方法销毁不健康的连接
     */
    public function testResetDestroysUnhealthyConnections(): void
    {
        // 获取上下文ID
        $contextId = $this->contextService->getId();

        // 创建连接 Mock
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们只需要一个连接对象的占位符，不需要其具体行为
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        $connection = $this->createMock(Connection::class);
        /**
         * 必须使用具体类 Pool 而不是接口，因为：
         * 1. Pool 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 测试中需要模拟池的行为，而不是实现细节
         * 3. 这是合理的，因为我们无法控制第三方库的设计
         */
        $pool = $this->createMock(Pool::class);

        // 设置服务ID
        $serviceId = 'test.service';

        // 先注册连接（这样 checkConnection 时才能设置为过期）
        $this->lifecycleHandler->registerConnection($connection);

        // 手动设置连接为过期状态（修改私有属性）
        $connectionId = $this->lifecycleHandler->getConnectionId($connection);
        $reflection = new \ReflectionProperty($this->lifecycleHandler, 'connectionStartTimes');
        $reflection->setAccessible(true);
        $startTimes = $reflection->getValue($this->lifecycleHandler);
        $startTimes[$connectionId] = time() - 3600; // 设置为1小时前
        $reflection->setValue($this->lifecycleHandler, $startTimes);

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection,
            ],
        ]);

        // 模拟池管理器返回池
        $poolManagerReflection = new \ReflectionClass($this->poolManager);
        $poolsProperty = $poolManagerReflection->getProperty('pools');
        $poolsProperty->setAccessible(true);
        $poolsProperty->setValue($this->poolManager, [$serviceId => $pool]);

        // 模拟池的计数
        $pool->method('count')->willReturn(5);

        // 调用 reset 方法
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
        // 创建 JoinPoint Mock
        $joinPoint = $this->createMock(JoinPoint::class);
        $serviceId = 'test.service';
        $joinPoint->method('getInternalServiceId')->willReturn($serviceId);

        // 获取上下文ID
        $contextId = $this->contextService->getId();

        // 创建 Pool 和 Connection Mock
        $pool = $this->createMock(Pool::class);
        $pool->method('count')->willReturn(10);
        $connection = $this->createMock(Connection::class);
        $resource = $this->createMock(\Redis::class);
        $connection->method('getResource')->willReturn($resource);

        // 模拟池管理器返回池和连接
        $poolManagerReflection = new \ReflectionClass($this->poolManager);
        $poolsProperty = $poolManagerReflection->getProperty('pools');
        $poolsProperty->setAccessible(true);
        $poolsProperty->setValue($this->poolManager, [$serviceId => $pool]);

        // 模拟连接池借出连接
        $pool->method('pop')->willReturn($connection);

        // 期望 setInstance 会被调用
        $joinPoint->expects($this->once())
            ->method('setInstance')
            ->with($resource)
        ;

        // 调用 pool 方法
        $this->aspect->pool($joinPoint);

        // 验证连接已被记录
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayHasKey($contextId, $borrowedConnections);
        self::assertArrayHasKey($serviceId, $borrowedConnections[$contextId]);
    }

    /**
     * 测试 redis 方法的基本功能
     */
    public function testRedis(): void
    {
        // 创建 JoinPoint Mock，非 __destruct 方法
        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint->method('getMethod')->willReturn('get');
        $serviceId = 'test.service';
        $joinPoint->method('getInternalServiceId')->willReturn($serviceId);

        // 获取上下文ID
        $contextId = $this->contextService->getId();

        // 创建 Pool 和 Connection Mock
        $pool = $this->createMock(Pool::class);
        $pool->method('count')->willReturn(10);
        $connection = $this->createMock(Connection::class);
        $resource = $this->createMock(\Redis::class);
        $connection->method('getResource')->willReturn($resource);

        // 模拟池管理器返回池和连接
        $poolManagerReflection = new \ReflectionClass($this->poolManager);
        $poolsProperty = $poolManagerReflection->getProperty('pools');
        $poolsProperty->setAccessible(true);
        $poolsProperty->setValue($this->poolManager, [$serviceId => $pool]);

        // 模拟连接池借出连接
        $pool->method('pop')->willReturn($connection);

        $joinPoint->expects($this->once())
            ->method('setInstance')
        ;

        // 调用 redis 方法
        $this->aspect->redis($joinPoint);

        // 验证连接已被记录
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
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

        // 创建连接和池的 Mock
        $connection = $this->createMock(Connection::class);
        $pool = $this->createMock(Pool::class);
        $serviceId = 'test.service';

        // 注册连接
        $this->lifecycleHandler->registerConnection($connection);

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection,
            ],
        ]);

        // 模拟池管理器返回池
        $poolManagerReflection = new \ReflectionClass($this->poolManager);
        $poolsProperty = $poolManagerReflection->getProperty('pools');
        $poolsProperty->setAccessible(true);
        $poolsProperty->setValue($this->poolManager, [$serviceId => $pool]);

        // 模拟池的计数
        $pool->method('count')->willReturn(5);

        // 调用 returnAll 方法
        $this->aspect->returnAll();

        // 验证 borrowedConnections 是否已清除
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }
}
