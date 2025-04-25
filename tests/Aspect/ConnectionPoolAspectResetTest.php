<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Aspect;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionLifecycleHandler;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager;
use Tourze\Symfony\RuntimeContextBundle\Service\ContextServiceInterface;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

class ConnectionPoolAspectResetTest extends TestCase
{
    protected ConnectionPoolAspect $aspect;
    protected ContextServiceInterface $contextService;
    protected ConnectionPoolManager $poolManager;
    protected ConnectionLifecycleHandler $lifecycleHandler;
    protected Logger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // 模拟 ConnectionPoolManager
        $this->poolManager = $this->createMock(ConnectionPoolManager::class);

        // 模拟 ConnectionLifecycleHandler
        $this->lifecycleHandler = $this->createMock(ConnectionLifecycleHandler::class);

        // 模拟 ContextServiceInterface
        $this->contextService = $this->createMock(ContextServiceInterface::class);

        // 模拟 Logger
        $this->logger = $this->createMock(Logger::class);

        // 创建 ConnectionPoolAspect 实例
        $this->aspect = new ConnectionPoolAspect(
            $this->poolManager,
            $this->lifecycleHandler,
            $this->contextService,
            $this->logger
        );
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
            ->with('重置连接池上下文', ['contextId' => 'test-context']);

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
        $connection = $this->createMock(Connection::class);
        $pool = $this->createMock(Pool::class);

        // 设置服务ID
        $serviceId = 'test.service';

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection
            ]
        ]);

        // 设置连接ID
        $connectionId = 'connection-id';
        $this->lifecycleHandler->expects($this->any())->method('getConnectionId')->willReturn($connectionId);

        // 期望 getPoolById 会被调用
        $this->poolManager->expects($this->once())
            ->method('getPoolById')
            ->with($serviceId)
            ->willReturn($pool);

        // 期望 checkConnection 会被调用
        $this->lifecycleHandler->expects($this->once())
            ->method('checkConnection')
            ->with($connection);

        // 期望 returnConnection 会被调用
        $this->poolManager->expects($this->once())
            ->method('returnConnection')
            ->with($serviceId, $pool, $connection);

        // 调用 reset 方法
        $this->aspect->reset();

        // 验证 borrowedConnections 是否已清除
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        $this->assertArrayNotHasKey($contextId, $borrowedConnections);
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
        $connection = $this->createMock(Connection::class);
        $pool = $this->createMock(Pool::class);

        // 设置服务ID
        $serviceId = 'test.service';

        // 手动设置借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId => $connection
            ]
        ]);

        // 设置连接ID
        $connectionId = 'connection-id';
        $this->lifecycleHandler->expects($this->any())->method('getConnectionId')->willReturn($connectionId);

        // 期望 getPoolById 会被调用
        $this->poolManager->expects($this->once())
            ->method('getPoolById')
            ->with($serviceId)
            ->willReturn($pool);

        // 期望 checkConnection 抛出异常
        $this->lifecycleHandler->expects($this->once())
            ->method('checkConnection')
            ->with($connection)
            ->willThrowException(new \Exception('连接不健康'));

        // 期望 destroyConnection 会被调用
        $this->poolManager->expects($this->once())
            ->method('destroyConnection')
            ->with($serviceId, $pool, $connection);

        // 调用 reset 方法
        $this->aspect->reset();

        // 验证 borrowedConnections 是否已清除
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        $this->assertArrayNotHasKey($contextId, $borrowedConnections);
    }
}
