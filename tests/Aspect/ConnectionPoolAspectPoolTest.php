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
 * @phpstan-ignore-next-line 测试用例 Tourze\Symfony\AopPoolBundle\Tests\Aspect\ConnectionPoolAspectPoolTest 的测试目标 Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect 是一个服务，因此不应直接继承自 PHPUnit\Framework\TestCase。
 */
#[CoversClass(ConnectionPoolAspect::class)]
final class ConnectionPoolAspectPoolTest extends TestCase
{
    protected ConnectionPoolAspect $aspect;

    protected ContextServiceInterface&MockObject $contextService;

    protected ConnectionPoolManager&MockObject $poolManager;

    protected ConnectionLifecycleHandler&MockObject $lifecycleHandler;

    protected Logger&MockObject $logger;

    protected function setUp(): void
    {
        // 模拟 ConnectionPoolManager
        $this->poolManager = $this->createMock(ConnectionPoolManager::class);

        // 模拟 ConnectionLifecycleHandler
        $this->lifecycleHandler = $this->createMock(ConnectionLifecycleHandler::class);

        // 模拟 ContextServiceInterface
        $this->contextService = $this->createMock(ContextServiceInterface::class);

        // 模拟 Logger
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
     * 测试 pool 方法创建新的连接池
     */
    public function testPoolCreatesNewConnectionPool(): void
    {
        // 模拟 JoinPoint
        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint->expects($this->any())->method('getInternalServiceId')->willReturn('test.service');
        $joinPoint->expects($this->once())->method('setInstance');

        // 模拟上下文ID
        $contextId = 'test-context';
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

        // 模拟连接池
        $pool = $this->createMock(Pool::class);
        $this->poolManager->expects($this->once())
            ->method('getPool')
            ->with('test.service', $joinPoint)
            ->willReturn($pool)
        ;

        // 模拟连接
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
        $resource = new \stdClass();
        $connection->expects($this->any())->method('getResource')->willReturn($resource);

        // 模拟借用连接
        $this->poolManager->expects($this->once())
            ->method('borrowConnection')
            ->with('test.service', $pool)
            ->willReturn($connection)
        ;

        // 调用 pool 方法
        $this->aspect->pool($joinPoint);

        // 验证 borrowedConnections 是否正确记录
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayHasKey($contextId, $borrowedConnections);
        self::assertArrayHasKey('test.service', $borrowedConnections[$contextId]);
        self::assertSame($connection, $borrowedConnections[$contextId]['test.service']);
    }

    /**
     * 测试 pool 方法重用现有连接
     */
    public function testPoolReusesExistingConnection(): void
    {
        // 模拟 JoinPoint
        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint->expects($this->any())->method('getInternalServiceId')->willReturn('test.service');

        // 模拟将被重用的连接
        $contextId = 'test-context';
        $serviceId = 'test.service';
        $resource = new \stdClass();
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
        $connection->expects($this->once())->method('getResource')->willReturn($resource);

        // 设置上下文ID
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

        // 模拟已有连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [$serviceId => $connection],
        ]);

        // 期望 setInstance 会被调用一次
        $joinPoint->expects($this->once())->method('setInstance')->with($resource);

        // 期望 getPool 不会被调用
        $this->poolManager->expects($this->never())->method('getPool');

        // 调用 pool 方法
        $this->aspect->pool($joinPoint);
    }

    /**
     * 测试 redis 方法处理 __destruct 调用
     */
    public function testRedisHandlesDestructMethod(): void
    {
        // 模拟 JoinPoint
        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint->expects($this->once())->method('getMethod')->willReturn('__destruct');
        $joinPoint->expects($this->once())->method('setReturnEarly')->with(true);
        $joinPoint->expects($this->once())->method('setReturnValue')->with(null);

        // 期望 pool 方法不会被调用
        $joinPoint->expects($this->never())->method('getInternalServiceId');

        // 调用 redis 方法
        $this->aspect->redis($joinPoint);
    }

    /**
     * 测试 redis 方法处理非 __destruct 调用
     */
    public function testRedisHandlesNonDestructMethod(): void
    {
        // 模拟 JoinPoint
        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint->expects($this->once())->method('getMethod')->willReturn('get');
        $joinPoint->expects($this->any())->method('getInternalServiceId')->willReturn('redis.service');
        $joinPoint->expects($this->once())->method('setInstance');

        // 模拟上下文ID
        $contextId = 'test-context';
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

        // 模拟连接池
        $pool = $this->createMock(Pool::class);
        $this->poolManager->expects($this->once())
            ->method('getPool')
            ->with('redis.service', $joinPoint)
            ->willReturn($pool)
        ;

        // 模拟连接
        $connection = $this->createMock(Connection::class);
        $resource = new \stdClass();
        $connection->expects($this->any())->method('getResource')->willReturn($resource);

        // 模拟借用连接
        $this->poolManager->expects($this->once())
            ->method('borrowConnection')
            ->with('redis.service', $pool)
            ->willReturn($connection)
        ;

        // 调用 redis 方法
        $this->aspect->redis($joinPoint);
    }

    /**
     * 测试 reset 方法
     */
    public function testReset(): void
    {
        // 模拟上下文ID
        $contextId = 'test-context';
        $serviceId = 'test.service';
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

        // 模拟连接
        $connection = $this->createMock(Connection::class);
        $connectionId = 'conn-123';

        // 设置已借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [$serviceId => $connection],
        ]);

        // 模拟连接池
        $pool = $this->createMock(Pool::class);
        $pool->expects($this->once())->method('count')->willReturn(5);

        // 模拟 lifecycleHandler
        $this->lifecycleHandler->expects($this->once())
            ->method('getConnectionId')
            ->with($connection)
            ->willReturn($connectionId)
        ;

        $this->lifecycleHandler->expects($this->once())
            ->method('checkConnection')
            ->with($connection)
        ;

        // 模拟 poolManager
        $this->poolManager->expects($this->once())
            ->method('getPoolById')
            ->with($serviceId)
            ->willReturn($pool)
        ;

        $this->poolManager->expects($this->once())
            ->method('returnConnection')
            ->with($serviceId, $pool, $connection)
        ;

        $this->poolManager->expects($this->never())
            ->method('cleanup')
        ;

        // 调用 reset 方法
        $this->aspect->reset();

        // 验证连接已被清理
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }

    /**
     * 测试 returnAll 方法处理空连接情况
     */
    public function testReturnAllWithNoConnections(): void
    {
        // 模拟上下文ID
        $contextId = 'test-context';
        $this->contextService->expects($this->once())->method('getId')->willReturn($contextId);

        // 设置空的借出连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', []);

        // 期望不会调用任何归还操作
        $this->poolManager->expects($this->never())->method('getPoolById');
        $this->poolManager->expects($this->never())->method('returnConnection');
        $this->poolManager->expects($this->never())->method('destroyConnection');

        // 调用 returnAll 方法
        $this->aspect->returnAll();
    }

    /**
     * 测试 returnAll 方法处理多个连接
     */
    public function testReturnAllWithMultipleConnections(): void
    {
        // 模拟上下文ID
        $contextId = 'test-context';
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

        // 模拟多个连接
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
        $connection1 = $this->createMock(Connection::class);
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
        $connection2 = $this->createMock(Connection::class);

        // 设置已借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                'service1' => $connection1,
                'service2' => $connection2,
            ],
        ]);

        // 模拟连接池
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
        $pool1 = $this->createMock(Pool::class);
        $pool1->expects($this->once())->method('count')->willReturn(3);

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
        $pool2 = $this->createMock(Pool::class);
        $pool2->expects($this->once())->method('count')->willReturn(4);

        // 模拟 lifecycleHandler
        $this->lifecycleHandler->expects($this->exactly(2))
            ->method('getConnectionId')
            ->willReturnMap([
                [$connection1, 'conn-1'],
                [$connection2, 'conn-2'],
            ])
        ;

        $this->lifecycleHandler->expects($this->exactly(2))
            ->method('checkConnection')
            ->willReturnMap([
                [$connection1],
                [$connection2],
            ])
        ;

        // 模拟 poolManager
        $this->poolManager->expects($this->exactly(2))
            ->method('getPoolById')
            ->willReturnMap([
                ['service1', $pool1],
                ['service2', $pool2],
            ])
        ;

        $this->poolManager->expects($this->exactly(2))
            ->method('returnConnection')
            ->willReturnMap([
                ['service1', $pool1, $connection1],
                ['service2', $pool2, $connection2],
            ])
        ;

        // 模拟随机清理（设置为不触发）
        mt_srand(50); // 确保 mt_rand(1, 100) 不等于 1

        // 调用 returnAll 方法
        $this->aspect->returnAll();

        // 验证连接已被清理
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }

    /**
     * 测试 returnAll 方法处理不健康的连接
     */
    public function testReturnAllWithUnhealthyConnection(): void
    {
        // 模拟上下文ID
        $contextId = 'test-context';
        $serviceId = 'test.service';
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

        // 模拟连接
        $connection = $this->createMock(Connection::class);
        $connectionId = 'conn-unhealthy';

        // 设置已借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [$serviceId => $connection],
        ]);

        // 模拟连接池
        $pool = $this->createMock(Pool::class);

        // 模拟 lifecycleHandler
        $this->lifecycleHandler->expects($this->once())
            ->method('getConnectionId')
            ->with($connection)
            ->willReturn($connectionId)
        ;

        // 模拟连接检查失败
        $this->lifecycleHandler->expects($this->once())
            ->method('checkConnection')
            ->with($connection)
            ->willThrowException(new \Exception('Connection unhealthy'))
        ;

        // 模拟 poolManager
        $this->poolManager->expects($this->once())
            ->method('getPoolById')
            ->with($serviceId)
            ->willReturn($pool)
        ;

        // 期望销毁连接而不是归还
        $this->poolManager->expects($this->never())
            ->method('returnConnection')
        ;

        $this->poolManager->expects($this->once())
            ->method('destroyConnection')
            ->with($serviceId, $pool, $connection)
        ;

        // 模拟随机清理（设置为不触发）
        mt_srand(50); // 确保 mt_rand(1, 100) 不等于 1

        // 调用 returnAll 方法
        $this->aspect->returnAll();

        // 验证连接已被清理
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }
}
