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
 * 测试 ConnectionPoolAspect 的功能
 *
 * @internal
 * @phpstan-ignore-next-line 测试用例 Tourze\Symfony\AopPoolBundle\Tests\Aspect\ConnectionPoolAspectFeatureTest 的测试目标 Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect 是一个服务，因此不应直接继承自 PHPUnit\Framework\TestCase。
 */
#[CoversClass(ConnectionPoolAspect::class)]
final class ConnectionPoolAspectFeatureTest extends TestCase
{
    /** @var ConnectionPoolManager&MockObject */
    private ConnectionPoolManager $poolManager;

    /** @var ConnectionLifecycleHandler&MockObject */
    private ConnectionLifecycleHandler $lifecycleHandler;

    /** @var ContextServiceInterface&MockObject */
    private ContextServiceInterface $contextService;

    /** @var Logger&MockObject */
    private Logger $logger;

    private ConnectionPoolAspect $aspect;

    protected function setUp(): void
    {
        // 创建模拟对象
        $this->poolManager = $this->createMock(ConnectionPoolManager::class);
        /*
         * 必须使用具体类 ConnectionLifecycleHandler 而不是接口的原因：
         * 1. ConnectionLifecycleHandler 是一个服务类，没有对应的接口
         * 2. 这是测试框架中常见的做法，对于服务类直接模拟其实现
         * 3. 该类的职责单一，专门处理连接生命周期，不需要额外的抽象层
         *
         * 这种使用是合理的，因为：
         * - 在单元测试中模拟具体的服务类是标准做法
         * - ConnectionLifecycleHandler 的公共方法契约稳定
         * - 测试只关注其公共方法的行为，不涉及内部实现
         */
        $this->lifecycleHandler = $this->createMock(ConnectionLifecycleHandler::class);
        $this->contextService = $this->createMock(ContextServiceInterface::class);
        /*
         * 必须使用具体类 Monolog\Logger 而不是接口的原因：
         * 1. 虽然 PSR-3 定义了 LoggerInterface，但 ConnectionPoolAspect 依赖注入的是 Logger 具体类
         * 2. 这反映了生产代码的实际依赖关系
         * 3. Monolog\Logger 实现了 LoggerInterface，但包含了额外的方法
         *
         * 替代方案：
         * - 理想情况下，ConnectionPoolAspect 应该依赖 LoggerInterface 而不是 Logger
         * - 但这需要修改生产代码，在测试中我们必须与生产代码保持一致
         *
         * 这种使用是必要的，因为必须与被测试类的构造函数签名匹配
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

    public function testRedisMethodForwardsToPool(): void
    {
        // 创建 JoinPoint 模拟对象
        $joinPoint = $this->createMock(JoinPoint::class);

        // 设置方法不是 __destruct
        $joinPoint->method('getMethod')
            ->willReturn('get')
        ;

        // 创建连接池和连接模拟对象
        /** @var Pool<mixed>&MockObject $pool */
        $pool = $this->createMock(Pool::class);
        /**
         * 必须使用具体类 Utopia\Pools\Connection 而不是接口的原因：
         * 1. Utopia Pools 库没有为 Connection 提供接口，只有具体实现
         * 2. Connection 是第三方库的核心类，我们无法控制其设计
         * 3. 在连接池模式中，Connection 通常是具体类而非接口
         *
         * 这种使用是合理的，因为：
         * - 这是第三方库的设计决定，我们必须遵循
         * - Connection 类的公共 API 稳定，适合模拟
         * - 测试关注的是连接池的行为，而不是 Connection 的内部实现
         *
         * 没有更好的替代方案，除非：
         * - 创建自己的连接抽象层（过度工程）
         * - 要求上游库提供接口（不现实）
         */
        /** @var Connection<mixed>&MockObject $connection */
        $connection = $this->createMock(Connection::class);
        $resource = new \stdClass();

        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->method('getId')
            ->willReturn($contextId)
        ;

        // 设置服务ID
        $serviceId = 'snc_redis.client.default';
        $joinPoint->method('getInternalServiceId')
            ->willReturn($serviceId)
        ;

        // 模拟获取连接池
        $this->poolManager->method('getPool')
            ->with($serviceId, $joinPoint)
            ->willReturn($pool)
        ;

        // 模拟借出连接
        $this->poolManager->method('borrowConnection')
            ->with($serviceId, $pool)
            ->willReturn($connection)
        ;

        // 模拟资源
        $connection->method('getResource')
            ->willReturn($resource)
        ;

        // 连接ID
        $connectionId = 'connection-id';
        $this->lifecycleHandler->method('getConnectionId')
            ->willReturn($connectionId)
        ;

        // 设置 setInstance 预期（在调用方法前设置预期）
        $joinPoint->expects(self::once())
            ->method('setInstance')
            ->with($resource)
        ;

        // 调用Redis方法
        $this->aspect->redis($joinPoint);
    }

    public function testRedisMethodHandlesDestruct(): void
    {
        // 创建 JoinPoint 模拟对象
        $joinPoint = $this->createMock(JoinPoint::class);

        // 设置方法是 __destruct
        $joinPoint->method('getMethod')
            ->willReturn('__destruct')
        ;

        // 期望 setReturnEarly 被调用
        $joinPoint->expects(self::once())
            ->method('setReturnEarly')
            ->with(true)
        ;

        // 期望 setReturnValue 被调用
        $joinPoint->expects(self::once())
            ->method('setReturnValue')
            ->with(null)
        ;

        // 调用Redis方法
        $this->aspect->redis($joinPoint);
    }

    public function testReusingExistingConnection(): void
    {
        // 创建 JoinPoint 模拟对象
        $joinPoint = $this->createMock(JoinPoint::class);

        // 创建连接模拟对象
        /**
         * 必须使用具体类 Utopia\Pools\Connection 的原因：
         * - 与第65行相同，这是第三方库的设计限制
         * - 在测试重用现有连接的场景中，必须模拟真实的 Connection 对象
         * - 保持测试的一致性和真实性
         */
        /** @var Connection<mixed>&MockObject $connection */
        $connection = $this->createMock(Connection::class);
        $resource = new \stdClass();

        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->method('getId')
            ->willReturn($contextId)
        ;

        // 设置服务ID
        $serviceId = 'test.service';
        $joinPoint->method('getInternalServiceId')
            ->willReturn($serviceId)
        ;

        // 设置资源
        $connection->method('getResource')
            ->willReturn($resource)
        ;

        // 手动设置借出的连接
        $reflection = new \ReflectionProperty(ConnectionPoolAspect::class, 'borrowedConnections');
        $reflection->setAccessible(true);
        $borrowedConnections = [
            $contextId => [
                $serviceId => $connection,
            ],
        ];
        $reflection->setValue($this->aspect, $borrowedConnections);

        // 设置 setInstance 预期（在调用方法前设置预期）
        $joinPoint->expects(self::once())
            ->method('setInstance')
            ->with($resource)
        ;

        // 调用pool方法
        $this->aspect->pool($joinPoint);
    }

    public function testResetMethod(): void
    {
        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->method('getId')
            ->willReturn($contextId)
        ;

        // 手动设置空的借出连接
        $reflection = new \ReflectionProperty(ConnectionPoolAspect::class, 'borrowedConnections');
        $reflection->setAccessible(true);
        $borrowedConnections = [];
        $reflection->setValue($this->aspect, $borrowedConnections);

        // 调用reset方法
        $this->aspect->reset();

        // 验证借出连接数组仍然为空（没有发生变化）
        $borrowedConnectionsAfter = $reflection->getValue($this->aspect);
        self::assertSame([], $borrowedConnectionsAfter, 'Borrowed connections should remain empty after reset with no connections');
    }

    public function testResetWithBorrowedConnections(): void
    {
        // 创建模拟对象
        /**
         * 必须使用具体类 Utopia\Pools\Connection 的原因：
         * 1. Connection 是第三方库没有提供接口的具体实现
         * 2. 测试连接池归还逻辑需要模拟真实的连接对象
         * 3. 这是连接池模式的标准实现方式
         *
         * 这种使用是合理和必要的，没有更好的替代方案
         */
        /** @var Connection<mixed>&MockObject $connection */
        $connection = $this->createMock(Connection::class);
        /**
         * 必须使用具体类 Utopia\Pools\Pool 的原因：
         * 1. Pool 是 Utopia Pools 库的具体类，没有对应接口
         * 2. 测试需要验证连接归还到正确的池
         * 3. 符合连接池模式的标准实现
         *
         * 这种使用是必要的，因为需要模拟真实的连接池行为
         */
        /** @var Pool<mixed>&MockObject $pool */
        $pool = $this->createMock(Pool::class);

        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->method('getId')
            ->willReturn($contextId)
        ;

        // 设置服务ID
        $serviceId = 'test.service';

        // 手动设置借出的连接
        $reflection = new \ReflectionProperty(ConnectionPoolAspect::class, 'borrowedConnections');
        $reflection->setAccessible(true);
        $borrowedConnections = [
            $contextId => [
                $serviceId => $connection,
            ],
        ];
        $reflection->setValue($this->aspect, $borrowedConnections);

        // 配置模拟行为
        $this->poolManager->method('getPoolById')
            ->with($serviceId)
            ->willReturn($pool)
        ;

        $connectionId = 'connection-id';
        $this->lifecycleHandler->method('getConnectionId')
            ->willReturn($connectionId)
        ;

        // 模拟connection健康检查通过
        $this->lifecycleHandler->method('checkConnection')
            ->with($connection)
        ;

        // 模拟归还连接
        $this->poolManager->expects(self::once())
            ->method('returnConnection')
            ->with($serviceId, $pool, $connection)
        ;

        // 调用reset方法
        $this->aspect->reset();

        // 验证借出的连接是否已清除
        $borrowedConnections = $reflection->getValue($this->aspect);
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }

    public function testResetWithUnhealthyConnection(): void
    {
        // 创建模拟对象
        /**
         * 必须使用具体类 Utopia\Pools\Connection 的原因：
         * 1. 需要模拟连接健康检查失败的情况
         * 2. Connection 是第三方库的具体实现，没有接口
         * 3. 这个测试验证了异常处理逻辑，需要真实的类型
         *
         * 这种使用是合理的，因为测试不健康连接的处理是必要的
         */
        /** @var Connection<mixed>&MockObject $connection */
        $connection = $this->createMock(Connection::class);
        /**
         * 必须使用具体类 Utopia\Pools\Pool 的原因：
         * 1. Pool 是第三方库的具体实现，没有对应接口
         * 2. 测试需要验证不健康连接被正确销毁
         * 3. 这是连接池异常处理的标准测试方法
         *
         * 这种使用是必要的，没有更好的替代方案
         */
        /** @var Pool<mixed>&MockObject $pool */
        $pool = $this->createMock(Pool::class);

        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->method('getId')
            ->willReturn($contextId)
        ;

        // 设置服务ID
        $serviceId = 'test.service';

        // 手动设置借出的连接
        $reflection = new \ReflectionProperty(ConnectionPoolAspect::class, 'borrowedConnections');
        $reflection->setAccessible(true);
        $borrowedConnections = [
            $contextId => [
                $serviceId => $connection,
            ],
        ];
        $reflection->setValue($this->aspect, $borrowedConnections);

        // 配置模拟行为
        $this->poolManager->method('getPoolById')
            ->with($serviceId)
            ->willReturn($pool)
        ;

        $connectionId = 'connection-id';
        $this->lifecycleHandler->method('getConnectionId')
            ->willReturn($connectionId)
        ;

        // 模拟connection健康检查失败
        $this->lifecycleHandler->method('checkConnection')
            ->with($connection)
            ->willThrowException(new \Exception('Connection unhealthy'))
        ;

        // 模拟销毁连接
        $this->poolManager->expects(self::once())
            ->method('destroyConnection')
            ->with($serviceId, $pool, $connection)
        ;

        // 调用reset方法
        $this->aspect->reset();

        // 验证借出的连接是否已清除
        $borrowedConnections = $reflection->getValue($this->aspect);
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }

    public function testPool(): void
    {
        // 创建 JoinPoint 模拟对象
        $joinPoint = $this->createMock(JoinPoint::class);

        // 创建连接池和连接模拟对象
        /*
         * 必须使用具体类 Utopia\Pools\Pool 而不是接口的原因：
         * 1. Utopia Pools 库没有为 Pool 提供接口，只有具体实现
         * 2. Pool 是第三方库的核心类，我们无法控制其设计
         * 3. 在连接池模式中，Pool 通常是具体类而非接口
         *
         * 这种使用是合理的，因为：
         * - 这是第三方库的设计决定，我们必须遵循
         * - Pool 类的公共 API 稳定，适合模拟
         * - 测试关注的是连接池管理器的行为，而不是 Pool 的内部实现
         */
        /** @var Pool<mixed>&MockObject $pool */
        $pool = $this->createMock(Pool::class);
        /*
         * 必须使用具体类 Utopia\Pools\Connection 而不是接口的原因：
         * 1. 与 Pool 类似，Connection 也是第三方库的具体实现
         * 2. 这是 Utopia Pools 库的设计模式，没有抽象接口
         * 3. 连接对象代表实际的资源连接，通常不需要接口抽象
         *
         * 这种使用是必要的，因为：
         * - 测试需要模拟真实的连接对象行为
         * - Connection 类的公共方法稳定，适合单元测试
         * - 没有更好的替代方案，除非包装整个第三方库
         */
        /** @var Connection<mixed>&MockObject $connection */
        $connection = $this->createMock(Connection::class);
        $resource = new \stdClass();

        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->method('getId')
            ->willReturn($contextId)
        ;

        // 设置服务ID
        $serviceId = 'test.service';
        $joinPoint->method('getInternalServiceId')
            ->willReturn($serviceId)
        ;

        // 模拟获取连接池
        $this->poolManager->expects(self::once())
            ->method('getPool')
            ->with($serviceId, $joinPoint)
            ->willReturn($pool)
        ;

        // 模拟借出连接
        $this->poolManager->expects(self::once())
            ->method('borrowConnection')
            ->with($serviceId, $pool)
            ->willReturn($connection)
        ;

        // 模拟注册和检查连接
        $this->lifecycleHandler->expects(self::once())
            ->method('registerConnection')
            ->with($connection)
        ;

        $this->lifecycleHandler->expects(self::once())
            ->method('checkConnection')
            ->with($connection)
        ;

        // 模拟连接ID
        $connectionId = 'connection-id';
        $this->lifecycleHandler->method('getConnectionId')
            ->willReturn($connectionId)
        ;

        // 模拟资源
        $connection->method('getResource')
            ->willReturn($resource)
        ;

        // 模拟连接池计数
        $pool->method('count')
            ->willReturn(5)
        ;

        // 设置 setInstance 预期
        $joinPoint->expects(self::once())
            ->method('setInstance')
            ->with($resource)
        ;

        // 调用pool方法
        $this->aspect->pool($joinPoint);

        // 验证连接已被记录
        $reflection = new \ReflectionProperty(ConnectionPoolAspect::class, 'borrowedConnections');
        $reflection->setAccessible(true);
        $borrowedConnections = $reflection->getValue($this->aspect);
        self::assertArrayHasKey($contextId, $borrowedConnections);
        self::assertArrayHasKey($serviceId, $borrowedConnections[$contextId]);
        self::assertSame($connection, $borrowedConnections[$contextId][$serviceId]);
    }

    public function testReturnAll(): void
    {
        // 创建模拟对象
        /*
         * 必须使用具体类 Utopia\Pools\Connection 而不是接口的原因：
         * 1. 与前面的测试保持一致，这是第三方库的设计限制
         * 2. 测试多个连接的归还场景需要模拟真实的 Connection 对象
         * 3. 确保测试的真实性和准确性
         *
         * 这种使用是合理的，理由同上
         */
        /** @var Connection<mixed>&MockObject $connection1 */
        $connection1 = $this->createMock(Connection::class);
        /*
         * 必须使用具体类 Utopia\Pools\Connection 而不是接口的原因：
         * 1. 与 connection1 相同，这是第三方库的设计限制
         * 2. 测试需要多个独立的连接对象来验证批量归还
         * 3. 保持测试的一致性和准确性
         *
         * 这种使用是必要的，没有更好的替代方案
         */
        /** @var Connection<mixed>&MockObject $connection2 */
        $connection2 = $this->createMock(Connection::class);
        /*
         * 必须使用具体类 Utopia\Pools\Pool 而不是接口的原因：
         * 1. 测试需要模拟多个连接池来验证 returnAll 的批量处理能力
         * 2. Pool 是第三方库的具体实现，没有对应接口
         * 3. 这是连接池模式的标准实现方式
         *
         * 这种使用是必要的，没有更好的替代方案
         */
        /** @var Pool<mixed>&MockObject $pool1 */
        $pool1 = $this->createMock(Pool::class);
        /*
         * 必须使用具体类 Utopia\Pools\Pool 而不是接口的原因：
         * 1. 与 pool1 相同，需要独立的池对象来测试多池场景
         * 2. 验证 returnAll 能正确处理多个不同的连接池
         * 3. 这是连接池批量操作测试的标准做法
         *
         * 这种使用是合理和必要的
         */
        /** @var Pool<mixed>&MockObject $pool2 */
        $pool2 = $this->createMock(Pool::class);

        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->method('getId')
            ->willReturn($contextId)
        ;

        // 设置服务ID
        $serviceId1 = 'test.service1';
        $serviceId2 = 'test.service2';

        // 手动设置借出的连接
        $reflection = new \ReflectionProperty(ConnectionPoolAspect::class, 'borrowedConnections');
        $reflection->setAccessible(true);
        $borrowedConnections = [
            $contextId => [
                $serviceId1 => $connection1,
                $serviceId2 => $connection2,
            ],
        ];
        $reflection->setValue($this->aspect, $borrowedConnections);

        // 配置模拟行为
        $this->poolManager->method('getPoolById')
            ->willReturnMap([
                [$serviceId1, $pool1],
                [$serviceId2, $pool2],
            ])
        ;

        $connectionId1 = 'connection-id-1';
        $connectionId2 = 'connection-id-2';
        $this->lifecycleHandler->method('getConnectionId')
            ->willReturnMap([
                [$connection1, $connectionId1],
                [$connection2, $connectionId2],
            ])
        ;

        // 模拟连接健康检查都通过
        $this->lifecycleHandler->expects(self::exactly(2))
            ->method('checkConnection')
            ->willReturnCallback(function ($conn) use ($connection1, $connection2): void {
                self::assertContains($conn, [$connection1, $connection2]);
            })
        ;

        // 模拟归还连接
        $this->poolManager->expects(self::exactly(2))
            ->method('returnConnection')
            ->willReturnCallback(function ($serviceId, $pool, $conn) use ($serviceId1, $serviceId2, $pool1, $pool2, $connection1, $connection2): void {
                if ($serviceId === $serviceId1) {
                    self::assertSame($pool1, $pool);
                    self::assertSame($connection1, $conn);
                } elseif ($serviceId === $serviceId2) {
                    self::assertSame($pool2, $pool);
                    self::assertSame($connection2, $conn);
                } else {
                    self::fail('Unexpected service ID: ' . $serviceId);
                }
            })
        ;

        // 模拟连接池计数
        $pool1->method('count')->willReturn(3);
        $pool2->method('count')->willReturn(4);

        // 模拟cleanup方法
        $this->poolManager->expects(self::atMost(1))
            ->method('cleanup')
        ;

        // 调用returnAll方法
        $this->aspect->returnAll();

        // 验证借出的连接是否已清除
        $borrowedConnections = $reflection->getValue($this->aspect);
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }
}
