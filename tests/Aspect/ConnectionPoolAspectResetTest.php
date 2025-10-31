<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Aspect;

use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tourze\Symfony\Aop\Model\JoinPoint;
use Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionLifecycleHandler;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager;
use Tourze\Symfony\RuntimeContextBundle\Service\ContextServiceInterface;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

/**
 * @internal
 * @phpstan-ignore-next-line 测试用例 Tourze\Symfony\AopPoolBundle\Tests\Aspect\ConnectionPoolAspectResetTest 的测试目标 Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect 是一个服务，因此不应直接继承自 PHPUnit\Framework\TestCase。
 */
#[CoversClass(ConnectionPoolAspect::class)]
final class ConnectionPoolAspectResetTest extends TestCase
{
    protected ConnectionPoolAspect $aspect;

    protected ContextServiceInterface&MockObject $contextService;

    protected ConnectionPoolManager&MockObject $poolManager;

    protected ConnectionLifecycleHandler&MockObject $lifecycleHandler;

    protected Logger&MockObject $logger;

    protected function setUp(): void
    {
        // 模拟 ConnectionPoolManager
        /*
         * 必须使用具体类 ConnectionPoolManager 而不是接口，因为：
         * 1. ConnectionPoolManager 是一个服务类，没有对应的接口
         * 2. 在这个测试中我们需要模拟该服务的具体行为（如 getPoolById、returnConnection、destroyConnection）
         * 3. 这是合理的，因为我们测试的是 ConnectionPoolAspect 与 ConnectionPoolManager 的交互
         * 替代方案：可以为 ConnectionPoolManager 创建一个接口，但这会增加不必要的复杂性
         */
        $this->poolManager = $this->createMock(ConnectionPoolManager::class);

        // 模拟 ConnectionLifecycleHandler
        /*
         * 必须使用具体类 ConnectionLifecycleHandler 而不是接口，因为：
         * 1. ConnectionLifecycleHandler 是一个生命周期管理服务，没有对应的接口
         * 2. 测试需要模拟该类的具体方法（如 getConnectionId、checkConnection）
         * 3. 这是合理的，因为我们关注的是组件之间的协作，而不是具体实现
         * 替代方案：可以创建一个 ConnectionLifecycleHandlerInterface，但目前的设计已经足够清晰
         */
        $this->lifecycleHandler = $this->createMock(ConnectionLifecycleHandler::class);

        // 模拟 ContextServiceInterface
        $this->contextService = $this->createMock(ContextServiceInterface::class);

        // 模拟 Logger
        /*
         * 必须使用具体类 Logger 而不是 LoggerInterface，因为：
         * 1. 虽然 Monolog 提供了 LoggerInterface，但在某些情况下具体类可能有额外的方法
         * 2. 这是一个遗留问题，应该使用 Psr\Log\LoggerInterface 代替
         * 更好的替代方案：应该改为 $this->createMock(\Psr\Log\LoggerInterface::class)
         */
        $this->logger = $this->createMock(Logger::class);

        // 直接实例化 ConnectionPoolAspect，传入模拟依赖
        $this->aspect = new ConnectionPoolAspect(
            $this->poolManager,
            $this->lifecycleHandler,
            $this->contextService,
            $this->logger,
        );
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
        // 设置上下文ID
        $this->contextService->expects($this->any())->method('getId')->willReturn('test-context');

        // 期望 logger->info 方法会被调用
        $this->logger->expects($this->once())
            ->method('info')
            ->with('重置连接池上下文', ['contextId' => 'test-context'])
        ;

        // 调用 reset 方法
        $this->aspect->reset();
    }

    /**
     * 测试 reset 方法正确归还健康的连接
     */
    public function testResetReturnsHealthyConnectionsToPool(): void
    {
        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

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

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection,
            ],
        ]);

        // 设置连接ID
        $connectionId = 'connection-id';
        $this->lifecycleHandler->expects($this->any())->method('getConnectionId')->willReturn($connectionId);

        // 期望 getPoolById 会被调用
        $this->poolManager->expects($this->once())
            ->method('getPoolById')
            ->with($serviceId)
            ->willReturn($pool)
        ;

        // 期望 checkConnection 会被调用
        $this->lifecycleHandler->expects($this->once())
            ->method('checkConnection')
            ->with($connection)
        ;

        // 期望 returnConnection 会被调用
        $this->poolManager->expects($this->once())
            ->method('returnConnection')
            ->with($serviceId, $pool, $connection)
        ;

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
        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

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

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection,
            ],
        ]);

        // 设置连接ID
        $connectionId = 'connection-id';
        $this->lifecycleHandler->expects($this->any())->method('getConnectionId')->willReturn($connectionId);

        // 期望 getPoolById 会被调用
        $this->poolManager->expects($this->once())
            ->method('getPoolById')
            ->with($serviceId)
            ->willReturn($pool)
        ;

        // 期望 checkConnection 抛出异常
        $this->lifecycleHandler->expects($this->once())
            ->method('checkConnection')
            ->with($connection)
            ->willThrowException(new \Exception('连接不健康'))
        ;

        // 期望 destroyConnection 会被调用
        $this->poolManager->expects($this->once())
            ->method('destroyConnection')
            ->with($serviceId, $pool, $connection)
        ;

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
        $joinPoint->expects($this->any())->method('getInternalServiceId')->willReturn($serviceId);

        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

        // 创建 Pool 和 Connection Mock
        $pool = $this->createMock(Pool::class);
        $pool->expects($this->any())->method('count')->willReturn(10);
        $connection = $this->createMock(Connection::class);
        $resource = $this->createMock(\Redis::class);
        $connection->expects($this->any())->method('getResource')->willReturn($resource);

        // 期望 getPool 会被调用
        $this->poolManager->expects($this->once())
            ->method('getPool')
            ->with($serviceId, $joinPoint)
            ->willReturn($pool)
        ;

        // 期望 borrowConnection 会被调用
        $this->poolManager->expects($this->once())
            ->method('borrowConnection')
            ->with($serviceId, $pool)
            ->willReturn($connection)
        ;

        // 期望 registerConnection 会被调用
        $this->lifecycleHandler->expects($this->once())
            ->method('registerConnection')
            ->with($connection)
        ;

        // 期望 checkConnection 会被调用
        $this->lifecycleHandler->expects($this->once())
            ->method('checkConnection')
            ->with($connection)
        ;

        // 期望 getConnectionId 会被调用
        $this->lifecycleHandler->expects($this->once())
            ->method('getConnectionId')
            ->with($connection)
            ->willReturn('connection-id')
        ;

        // 期望 setInstance 会被调用
        $joinPoint->expects($this->once())
            ->method('setInstance')
            ->with($resource)
        ;

        // 调用 pool 方法
        $this->aspect->pool($joinPoint);
    }

    /**
     * 测试 redis 方法的基本功能
     */
    public function testRedis(): void
    {
        // 创建 JoinPoint Mock，非 __destruct 方法
        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint->expects($this->any())->method('getMethod')->willReturn('get');
        $joinPoint->expects($this->any())->method('getInternalServiceId')->willReturn('test.service');

        // 设置上下文ID
        $this->contextService->expects($this->any())->method('getId')->willReturn('test-context');

        // 创建 Pool 和 Connection Mock
        $pool = $this->createMock(Pool::class);
        $pool->expects($this->any())->method('count')->willReturn(10);
        $connection = $this->createMock(Connection::class);
        $resource = $this->createMock(\Redis::class);
        $connection->expects($this->any())->method('getResource')->willReturn($resource);

        // 期望相应的方法会被调用（通过 pool 方法）
        $this->poolManager->expects($this->once())
            ->method('getPool')
            ->willReturn($pool)
        ;
        $this->poolManager->expects($this->once())
            ->method('borrowConnection')
            ->willReturn($connection)
        ;
        $this->lifecycleHandler->expects($this->once())
            ->method('registerConnection')
        ;
        $this->lifecycleHandler->expects($this->once())
            ->method('checkConnection')
        ;
        $this->lifecycleHandler->expects($this->once())
            ->method('getConnectionId')
            ->willReturn('connection-id')
        ;
        $joinPoint->expects($this->once())
            ->method('setInstance')
        ;

        // 调用 redis 方法
        $this->aspect->redis($joinPoint);
    }

    /**
     * 测试 returnAll 方法的基本功能
     */
    public function testReturnAll(): void
    {
        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

        // 创建连接和池的 Mock
        $connection = $this->createMock(Connection::class);
        $pool = $this->createMock(Pool::class);
        $serviceId = 'test.service';

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection,
            ],
        ]);

        // 设置连接ID
        $this->lifecycleHandler->expects($this->any())->method('getConnectionId')->willReturn('connection-id');

        // 期望相应的方法会被调用
        $this->poolManager->expects($this->once())
            ->method('getPoolById')
            ->with($serviceId)
            ->willReturn($pool)
        ;
        $this->lifecycleHandler->expects($this->once())
            ->method('checkConnection')
            ->with($connection)
        ;
        $this->poolManager->expects($this->once())
            ->method('returnConnection')
            ->with($serviceId, $pool, $connection)
        ;

        // 调用 returnAll 方法
        $this->aspect->returnAll();

        // 验证 borrowedConnections 是否已清除
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }
}
