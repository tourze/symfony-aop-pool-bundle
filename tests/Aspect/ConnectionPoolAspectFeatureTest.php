<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Aspect;

use Monolog\Logger;
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
 */
class ConnectionPoolAspectFeatureTest extends TestCase
{
    private ConnectionPoolManager $poolManager;
    private ConnectionLifecycleHandler $lifecycleHandler;
    private ContextServiceInterface $contextService;
    private Logger $logger;
    private ConnectionPoolAspect $aspect;

    protected function setUp(): void
    {
        parent::setUp();

        // 创建模拟对象
        $this->poolManager = $this->createMock(ConnectionPoolManager::class);
        $this->lifecycleHandler = $this->createMock(ConnectionLifecycleHandler::class);
        $this->contextService = $this->createMock(ContextServiceInterface::class);
        $this->logger = $this->createMock(Logger::class);

        // 创建 ConnectionPoolAspect 实例
        $this->aspect = new ConnectionPoolAspect(
            $this->poolManager,
            $this->lifecycleHandler,
            $this->contextService,
            $this->logger
        );
    }

    public function testRedisMethodForwardsToPool(): void
    {
        // 创建 JoinPoint 模拟对象
        $joinPoint = $this->createMock(JoinPoint::class);

        // 设置方法不是 __destruct
        $joinPoint->method('getMethod')
            ->willReturn('get');

        // 创建连接池和连接模拟对象
        $pool = $this->createMock(Pool::class);
        $connection = $this->createMock(Connection::class);
        $resource = new \stdClass();

        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->method('getId')
            ->willReturn($contextId);

        // 设置服务ID
        $serviceId = 'snc_redis.client.default';
        $joinPoint->method('getInternalServiceId')
            ->willReturn($serviceId);

        // 模拟获取连接池
        $this->poolManager->method('getPool')
            ->with($serviceId, $joinPoint)
            ->willReturn($pool);

        // 模拟借出连接
        $this->poolManager->method('borrowConnection')
            ->with($serviceId, $pool)
            ->willReturn($connection);

        // 模拟资源
        $connection->method('getResource')
            ->willReturn($resource);

        // 连接ID
        $connectionId = 'connection-id';
        $this->lifecycleHandler->method('getConnectionId')
            ->willReturn($connectionId);

        // 设置 setInstance 预期（在调用方法前设置预期）
        $joinPoint->expects($this->once())
            ->method('setInstance')
            ->with($resource);

        // 调用Redis方法
        $this->aspect->redis($joinPoint);
    }

    public function testRedisMethodHandlesDestruct(): void
    {
        // 创建 JoinPoint 模拟对象
        $joinPoint = $this->createMock(JoinPoint::class);

        // 设置方法是 __destruct
        $joinPoint->method('getMethod')
            ->willReturn('__destruct');

        // 期望 setReturnEarly 被调用
        $joinPoint->expects($this->once())
            ->method('setReturnEarly')
            ->with(true);

        // 期望 setReturnValue 被调用
        $joinPoint->expects($this->once())
            ->method('setReturnValue')
            ->with(null);

        // 调用Redis方法
        $this->aspect->redis($joinPoint);
    }

    public function testReusingExistingConnection(): void
    {
        // 创建 JoinPoint 模拟对象
        $joinPoint = $this->createMock(JoinPoint::class);

        // 创建连接模拟对象
        $connection = $this->createMock(Connection::class);
        $resource = new \stdClass();

        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->method('getId')
            ->willReturn($contextId);

        // 设置服务ID
        $serviceId = 'test.service';
        $joinPoint->method('getInternalServiceId')
            ->willReturn($serviceId);

        // 设置资源
        $connection->method('getResource')
            ->willReturn($resource);

        // 手动设置借出的连接
        $reflection = new \ReflectionProperty(ConnectionPoolAspect::class, 'borrowedConnections');
        $reflection->setAccessible(true);
        $borrowedConnections = [
            $contextId => [
                $serviceId => $connection
            ]
        ];
        $reflection->setValue($this->aspect, $borrowedConnections);

        // 设置 setInstance 预期（在调用方法前设置预期）
        $joinPoint->expects($this->once())
            ->method('setInstance')
            ->with($resource);

        // 调用pool方法
        $this->aspect->pool($joinPoint);
    }

    public function testResetMethod(): void
    {
        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->method('getId')
            ->willReturn($contextId);

        // 手动设置空的借出连接
        $reflection = new \ReflectionProperty(ConnectionPoolAspect::class, 'borrowedConnections');
        $reflection->setAccessible(true);
        $borrowedConnections = [];
        $reflection->setValue($this->aspect, $borrowedConnections);

        // 调用reset方法
        $this->aspect->reset();

        // 没有连接，什么都不会发生
        $this->assertTrue(true);
    }

    public function testResetWithBorrowedConnections(): void
    {
        // 创建模拟对象
        $connection = $this->createMock(Connection::class);
        $pool = $this->createMock(Pool::class);

        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->method('getId')
            ->willReturn($contextId);

        // 设置服务ID
        $serviceId = 'test.service';

        // 手动设置借出的连接
        $reflection = new \ReflectionProperty(ConnectionPoolAspect::class, 'borrowedConnections');
        $reflection->setAccessible(true);
        $borrowedConnections = [
            $contextId => [
                $serviceId => $connection
            ]
        ];
        $reflection->setValue($this->aspect, $borrowedConnections);

        // 配置模拟行为
        $this->poolManager->method('getPoolById')
            ->with($serviceId)
            ->willReturn($pool);

        $connectionId = 'connection-id';
        $this->lifecycleHandler->method('getConnectionId')
            ->willReturn($connectionId);

        // 模拟connection健康检查通过
        $this->lifecycleHandler->method('checkConnection')
            ->with($connection);

        // 模拟归还连接
        $this->poolManager->expects($this->once())
            ->method('returnConnection')
            ->with($serviceId, $pool, $connection);

        // 调用reset方法
        $this->aspect->reset();

        // 验证借出的连接是否已清除
        $borrowedConnections = $reflection->getValue($this->aspect);
        $this->assertArrayNotHasKey($contextId, $borrowedConnections);
    }

    public function testResetWithUnhealthyConnection(): void
    {
        // 创建模拟对象
        $connection = $this->createMock(Connection::class);
        $pool = $this->createMock(Pool::class);

        // 设置上下文ID
        $contextId = 'test-context';
        $this->contextService->method('getId')
            ->willReturn($contextId);

        // 设置服务ID
        $serviceId = 'test.service';

        // 手动设置借出的连接
        $reflection = new \ReflectionProperty(ConnectionPoolAspect::class, 'borrowedConnections');
        $reflection->setAccessible(true);
        $borrowedConnections = [
            $contextId => [
                $serviceId => $connection
            ]
        ];
        $reflection->setValue($this->aspect, $borrowedConnections);

        // 配置模拟行为
        $this->poolManager->method('getPoolById')
            ->with($serviceId)
            ->willReturn($pool);

        $connectionId = 'connection-id';
        $this->lifecycleHandler->method('getConnectionId')
            ->willReturn($connectionId);

        // 模拟connection健康检查失败
        $this->lifecycleHandler->method('checkConnection')
            ->with($connection)
            ->willThrowException(new \Exception('Connection unhealthy'));

        // 模拟销毁连接
        $this->poolManager->expects($this->once())
            ->method('destroyConnection')
            ->with($serviceId, $pool, $connection);

        // 调用reset方法
        $this->aspect->reset();

        // 验证借出的连接是否已清除
        $borrowedConnections = $reflection->getValue($this->aspect);
        $this->assertArrayNotHasKey($contextId, $borrowedConnections);
    }
}
