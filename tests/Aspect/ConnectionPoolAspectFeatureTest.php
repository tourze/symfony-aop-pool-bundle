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
final class ConnectionPoolAspectFeatureTest extends AbstractIntegrationTestCase
{
    private ConnectionPoolAspect $aspect;

    private ConnectionPoolManager $poolManager;

    private ConnectionLifecycleHandler $lifecycleHandler;

    private ContextServiceInterface $contextService;

    protected function onSetUp(): void
    {
        // 从容器获取真实服务实例
        $this->aspect = self::getService(ConnectionPoolAspect::class);
        $this->poolManager = self::getService(ConnectionPoolManager::class);
        $this->lifecycleHandler = self::getService(ConnectionLifecycleHandler::class);
        $this->contextService = self::getService(ContextServiceInterface::class);
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

    public function testRedisMethodForwardsToPool(): void
    {
        // 创建 JoinPoint 模拟对象
        /**
         * 必须使用 Mock 的原因：
         * 1. JoinPoint 是 AOP 框架的核心类，在测试中模拟其行为是标准做法
         * 2. 我们需要控制其返回值以测试不同场景
         */
        $joinPoint = $this->createMock(JoinPoint::class);

        // 设置方法不是 __destruct
        $joinPoint->method('getMethod')
            ->willReturn('get')
        ;

        // 创建连接池和连接模拟对象
        /**
         * 必须使用具体类 Utopia\Pools\Pool 的原因：
         * 1. Pool 是第三方库的具体实现，没有接口
         * 2. 这是外部依赖，符合"除非是网络请求"的例外条件
         */
        /** @var Pool<mixed>&\PHPUnit\Framework\MockObject\MockObject $pool */
        $pool = $this->createMock(Pool::class);
        /**
         * 必须使用具体类 Utopia\Pools\Connection 的原因：
         * 1. Connection 是第三方库的具体实现，没有接口
         * 2. 这是外部依赖，符合"除非是网络请求"的例外条件
         */
        /** @var Connection<mixed>&\PHPUnit\Framework\MockObject\MockObject $connection */
        $connection = $this->createMock(Connection::class);
        $resource = new \stdClass();

        // 设置服务ID
        $serviceId = 'snc_redis.client.default';
        $joinPoint->method('getInternalServiceId')
            ->willReturn($serviceId)
        ;

        // 模拟资源
        $connection->method('getResource')
            ->willReturn($resource)
        ;

        // 模拟池的 pop 方法返回连接
        $pool->method('pop')
            ->willReturn($connection)
        ;

        // 模拟池计数
        $pool->method('count')
            ->willReturn(5)
        ;

        // 通过反射注入依赖到 poolManager，使其返回我们的 Mock 对象
        $this->setPrivateProperty($this->poolManager, 'pools', [
            $serviceId => $pool,
        ]);

        // 初始化 poolStats 以防止统计错误
        $this->setPrivateProperty($this->poolManager, 'poolStats', [
            $serviceId => [
                'borrowed' => 0,
                'available' => 5,
                'total' => 5,
                'created' => 0,
                'destroyed' => 0,
            ],
        ]);

        // 注册连接到 lifecycleHandler
        $this->lifecycleHandler->registerConnection($connection);

        // 设置 setInstance 预期
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
         * - 这是第三方库的设计限制，没有接口
         * - 在测试重用现有连接的场景中，必须模拟真实的 Connection 对象
         */
        /** @var Connection<mixed>&\PHPUnit\Framework\MockObject\MockObject $connection */
        $connection = $this->createMock(Connection::class);
        $resource = new \stdClass();

        // 获取当前上下文ID
        $contextId = $this->contextService->getId();

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
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection,
            ],
        ]);

        // 设置 setInstance 预期
        $joinPoint->expects(self::once())
            ->method('setInstance')
            ->with($resource)
        ;

        // 调用pool方法
        $this->aspect->pool($joinPoint);
    }

    public function testResetMethod(): void
    {
        // 获取当前上下文ID
        $contextId = $this->contextService->getId();

        // 手动设置空的借出连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', []);

        // 调用reset方法
        $this->aspect->reset();

        // 验证借出连接数组仍然为空
        $borrowedConnectionsAfter = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertSame([], $borrowedConnectionsAfter, 'Borrowed connections should remain empty after reset with no connections');
    }

    public function testResetWithBorrowedConnections(): void
    {
        // 创建模拟对象
        /**
         * 必须使用具体类 Utopia\Pools\Connection 的原因：
         * 1. Connection 是第三方库的具体实现，没有接口
         * 2. 测试需要验证连接归还逻辑
         */
        /** @var Connection<mixed>&\PHPUnit\Framework\MockObject\MockObject $connection */
        $connection = $this->createMock(Connection::class);
        /**
         * 必须使用具体类 Utopia\Pools\Pool 的原因：
         * 1. Pool 是第三方库的具体实现，没有接口
         * 2. 测试需要验证连接归还到正确的池
         */
        /** @var Pool<mixed>&\PHPUnit\Framework\MockObject\MockObject $pool */
        $pool = $this->createMock(Pool::class);

        // 获取当前上下文ID
        $contextId = $this->contextService->getId();

        // 设置服务ID
        $serviceId = 'test.service';

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection,
            ],
        ]);

        // 注册连接，使得 lifecycleHandler 能够识别它
        $this->lifecycleHandler->registerConnection($connection);

        // 将池注入到 poolManager
        $this->setPrivateProperty($this->poolManager, 'pools', [
            $serviceId => $pool,
        ]);

        // 模拟池计数
        $pool->method('count')
            ->willReturn(5)
        ;

        // 调用reset方法
        $this->aspect->reset();

        // 验证借出的连接是否已清除
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }

    public function testResetWithUnhealthyConnection(): void
    {
        // 创建模拟对象
        /**
         * 必须使用具体类 Utopia\Pools\Connection 的原因：
         * 1. 需要模拟连接健康检查失败的情况
         * 2. Connection 是第三方库的具体实现，没有接口
         */
        /** @var Connection<mixed>&\PHPUnit\Framework\MockObject\MockObject $connection */
        $connection = $this->createMock(Connection::class);
        /**
         * 必须使用具体类 Utopia\Pools\Pool 的原因：
         * 1. Pool 是第三方库的具体实现，没有接口
         * 2. 测试需要验证不健康连接被正确销毁
         */
        /** @var Pool<mixed>&\PHPUnit\Framework\MockObject\MockObject $pool */
        $pool = $this->createMock(Pool::class);

        // 获取当前上下文ID
        $contextId = $this->contextService->getId();

        // 设置服务ID
        $serviceId = 'test.service';

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection,
            ],
        ]);

        // 注册连接
        $this->lifecycleHandler->registerConnection($connection);

        // 将池注入到 poolManager
        $this->setPrivateProperty($this->poolManager, 'pools', [
            $serviceId => $pool,
        ]);

        // 模拟池计数
        $pool->method('count')
            ->willReturn(5)
        ;

        // 设置连接过期，使健康检查失败
        $connectionId = $this->lifecycleHandler->getConnectionId($connection);
        $startTimesReflection = new \ReflectionProperty($this->lifecycleHandler, 'connectionStartTimes');
        $startTimesReflection->setAccessible(true);
        $startTimes = $startTimesReflection->getValue($this->lifecycleHandler);
        $startTimes[$connectionId] = time() - 3600; // 设置为1小时前
        $startTimesReflection->setValue($this->lifecycleHandler, $startTimes);

        // 调用reset方法
        $this->aspect->reset();

        // 验证借出的连接是否已清除
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }

    public function testPool(): void
    {
        // 创建 JoinPoint 模拟对象
        $joinPoint = $this->createMock(JoinPoint::class);

        // 创建连接池和连接模拟对象
        /**
         * 必须使用具体类 Utopia\Pools\Pool 的原因：
         * 1. Pool 是第三方库的具体实现，没有接口
         * 2. 这是外部依赖
         */
        /** @var Pool<mixed>&\PHPUnit\Framework\MockObject\MockObject $pool */
        $pool = $this->createMock(Pool::class);
        /**
         * 必须使用具体类 Utopia\Pools\Connection 的原因：
         * 1. Connection 是第三方库的具体实现，没有接口
         * 2. 这是外部依赖
         */
        /** @var Connection<mixed>&\PHPUnit\Framework\MockObject\MockObject $connection */
        $connection = $this->createMock(Connection::class);
        $resource = new \stdClass();

        // 获取当前上下文ID
        $contextId = $this->contextService->getId();

        // 设置服务ID
        $serviceId = 'test.service';
        $joinPoint->method('getInternalServiceId')
            ->willReturn($serviceId)
        ;

        // 模拟资源
        $connection->method('getResource')
            ->willReturn($resource)
        ;

        // 模拟池的 pop 方法返回连接
        $pool->method('pop')
            ->willReturn($connection)
        ;

        // 模拟连接池计数
        $pool->method('count')
            ->willReturn(5)
        ;

        // 注册连接到 lifecycleHandler
        $this->lifecycleHandler->registerConnection($connection);

        // 注入池和连接到 poolManager
        $this->setPrivateProperty($this->poolManager, 'pools', [
            $serviceId => $pool,
        ]);

        // 初始化 poolStats
        $this->setPrivateProperty($this->poolManager, 'poolStats', [
            $serviceId => [
                'borrowed' => 0,
                'available' => 5,
                'total' => 5,
                'created' => 0,
                'destroyed' => 0,
            ],
        ]);

        // 设置 setInstance 预期
        $joinPoint->expects(self::once())
            ->method('setInstance')
            ->with($resource)
        ;

        // 调用pool方法
        $this->aspect->pool($joinPoint);

        // 验证连接已被记录
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayHasKey($contextId, $borrowedConnections);
        self::assertArrayHasKey($serviceId, $borrowedConnections[$contextId]);
        self::assertSame($connection, $borrowedConnections[$contextId][$serviceId]);
    }

    public function testReturnAll(): void
    {
        // 创建模拟对象
        /**
         * 必须使用具体类 Utopia\Pools\Connection 的原因：
         * 1. Connection 是第三方库的具体实现，没有接口
         * 2. 测试需要模拟多个连接的归还
         */
        /** @var Connection<mixed>&\PHPUnit\Framework\MockObject\MockObject $connection1 */
        $connection1 = $this->createMock(Connection::class);
        /** @var Connection<mixed>&\PHPUnit\Framework\MockObject\MockObject $connection2 */
        $connection2 = $this->createMock(Connection::class);
        /**
         * 必须使用具体类 Utopia\Pools\Pool 的原因：
         * 1. Pool 是第三方库的具体实现，没有接口
         * 2. 测试需要模拟多个连接池
         */
        /** @var Pool<mixed>&\PHPUnit\Framework\MockObject\MockObject $pool1 */
        $pool1 = $this->createMock(Pool::class);
        /** @var Pool<mixed>&\PHPUnit\Framework\MockObject\MockObject $pool2 */
        $pool2 = $this->createMock(Pool::class);

        // 获取当前上下文ID
        $contextId = $this->contextService->getId();

        // 设置服务ID
        $serviceId1 = 'test.service1';
        $serviceId2 = 'test.service2';

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId1 => $connection1,
                $serviceId2 => $connection2,
            ],
        ]);

        // 注册连接
        $this->lifecycleHandler->registerConnection($connection1);
        $this->lifecycleHandler->registerConnection($connection2);

        // 将池注入到 poolManager
        $this->setPrivateProperty($this->poolManager, 'pools', [
            $serviceId1 => $pool1,
            $serviceId2 => $pool2,
        ]);

        // 模拟连接池计数
        $pool1->method('count')->willReturn(3);
        $pool2->method('count')->willReturn(4);

        // 调用returnAll方法
        $this->aspect->returnAll();

        // 验证借出的连接是否已清除
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }
}
