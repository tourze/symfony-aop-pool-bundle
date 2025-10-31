<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Aspect;

use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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
 * @phpstan-ignore-next-line 测试用例 Tourze\Symfony\AopPoolBundle\Tests\Aspect\ConnectionPoolAspectTest 的测试目标 Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect 是一个服务，因此不应直接继承自 PHPUnit\Framework\TestCase。
 */
#[CoversClass(ConnectionPoolAspect::class)]
final class ConnectionPoolAspectTest extends TestCase
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
         * 1. ConnectionPoolManager 是一个服务类，没有定义对应的接口
         * 2. 我们需要模拟该类的多个方法来测试 ConnectionPoolAspect 的行为
         * 3. 这是合理的，因为我们测试的是 Aspect 与 PoolManager 的交互
         */
        $this->poolManager = $this->createMock(ConnectionPoolManager::class);

        // 模拟 ConnectionLifecycleHandler
        /*
         * 必须使用具体类 ConnectionLifecycleHandler 而不是接口，因为：
         * 1. ConnectionLifecycleHandler 是一个内部服务类，没有定义对应的接口
         * 2. 我们需要模拟该类的方法来测试连接生命周期管理
         * 3. 这是合理的，因为该类是内部实现细节
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
        // 创建 JoinPoint Mock
        /*
         * 必须使用具体类 JoinPoint 而不是接口，因为：
         * 1. JoinPoint 是 AOP 框架提供的具体类，没有定义对应的接口
         * 2. 我们需要模拟该类的多个方法来测试 Aspect 的行为
         * 3. 这是合理的，因为 JoinPoint 是 AOP 框架的核心概念，其设计决定不在我们的控制范围内
         */
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
        // 创建 JoinPoint Mock
        /*
         * 必须使用具体类 JoinPoint 而不是接口，因为：
         * 1. JoinPoint 是 AOP 框架提供的具体类，没有定义对应的接口
         * 2. 我们需要模拟该类的多个方法来测试 Aspect 的行为
         * 3. 这是合理的，因为 JoinPoint 是 AOP 框架的核心概念，其设计决定不在我们的控制范围内
         */
        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint->expects($this->any())->method('getMethod')->willReturn('get');

        // 设置服务ID
        $serviceId = 'test.service';
        $joinPoint->expects($this->any())->method('getInternalServiceId')->willReturn($serviceId);

        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

        // 创建 Pool Mock
        /**
         * 为什么必须使用具体类：
         * - Utopia\Pools\Pool 是一个具体类，没有对应的接口
         * - 这是第三方库 utopia-php/pools 提供的连接池实现类
         *
         * 这种使用是否合理：
         * - 合理，因为这是在测试连接池功能，需要模拟实际的池对象
         * - Pool 类提供了 count()、pop()、push() 等必需的方法
         *
         * 是否有更好的替代方案：
         * - 暂无，除非创建自定义的池接口并包装 Utopia\Pools\Pool
         * - 但这会增加不必要的抽象层，对于第三方库的集成测试来说过度设计
         */
        $pool = $this->createMock(Pool::class);
        $pool->expects($this->any())->method('count')->willReturn(10);

        // 创建 Connection Mock
        /**
         * 为什么必须使用具体类：
         * - Utopia\Pools\Connection 是一个具体类，没有对应的接口
         * - 这是第三方库 utopia-php/pools 提供的连接实现类
         *
         * 这种使用是否合理：
         * - 合理，因为这是在测试连接池功能，需要模拟实际的连接对象
         * - Connection 类提供了 getResource() 等必需的方法
         *
         * 是否有更好的替代方案：
         * - 暂无，除非创建自定义的连接接口并包装 Utopia\Pools\Connection
         * - 但这会增加不必要的抽象层，对于第三方库的集成测试来说过度设计
         */
        $connection = $this->createMock(Connection::class);
        /**
         * 为什么必须使用具体类：
         * - Redis 是 PHP 扩展提供的具体类，没有对应的接口
         * - 这是为了测试 Redis 连接的特定行为
         *
         * 这种使用是否合理：
         * - 合理，因为我们需要模拟 Redis 实例来测试连接处理逻辑
         * - Redis 类是标准的 PHP 扩展类
         *
         * 是否有更好的替代方案：
         * - 可以使用 Predis 等提供接口的库，但这会改变现有的架构
         * - 当前的使用方式是测试 Redis 扩展集成的标准做法
         */
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

        // 调用 redis 方法
        $this->aspect->redis($joinPoint);
    }

    public function testResetWithNoBorrowedConnections(): void
    {
        // 设置上下文ID
        $this->contextService->expects($this->any())->method('getId')->willReturn('test-context');

        // 期望 logger->debug 方法会被调用
        $this->logger->expects($this->once())
            ->method('info')
            ->with('重置连接池上下文', ['contextId' => 'test-context'])
        ;

        // 调用 reset 方法
        $this->aspect->reset();
    }

    public function testResetWithBorrowedConnections(): void
    {
        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

        // 创建连接 Mock
        /*
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要一个连接对象来测试 reset 方法的行为
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        $connection = $this->createMock(Connection::class);
        /*
         * 必须使用具体类 Pool 而不是接口，因为：
         * 1. Pool 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要模拟池的行为来测试连接归还逻辑
         * 3. 这是合理的，因为这是第三方库的设计决定
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

    public function testResetWithUnhealthyConnection(): void
    {
        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

        // 创建连接 Mock
        /*
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要一个连接对象来测试 reset 方法的行为
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        $connection = $this->createMock(Connection::class);
        /*
         * 必须使用具体类 Pool 而不是接口，因为：
         * 1. Pool 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要模拟池的行为来测试连接归还逻辑
         * 3. 这是合理的，因为这是第三方库的设计决定
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
            ->willThrowException(new ConnectionUnhealthyException('连接不健康'))
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
