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

class ConnectionPoolAspectPoolTest extends TestCase
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

        // 重置 ENV 设置
        $_ENV['SERVICE_POOL_GET_RETRY_ATTEMPTS'] = '5'; // 使用合理的重试次数
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
            ->willReturn($pool);

        // 模拟连接
        $connection = $this->createMock(Connection::class);
        $resource = new \stdClass();
        $connection->expects($this->any())->method('getResource')->willReturn($resource);

        // 模拟借用连接
        $this->poolManager->expects($this->once())
            ->method('borrowConnection')
            ->with('test.service', $pool)
            ->willReturn($connection);

        // 调用 pool 方法
        $this->aspect->pool($joinPoint);

        // 验证 borrowedConnections 是否正确记录
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        $this->assertArrayHasKey($contextId, $borrowedConnections);
        $this->assertArrayHasKey('test.service', $borrowedConnections[$contextId]);
        $this->assertSame($connection, $borrowedConnections[$contextId]['test.service']);
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
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('getResource')->willReturn($resource);

        // 设置上下文ID
        $this->contextService->expects($this->any())->method('getId')->willReturn($contextId);

        // 模拟已有连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [$serviceId => $connection]
        ]);

        // 期望 setInstance 会被调用一次
        $joinPoint->expects($this->once())->method('setInstance')->with($resource);

        // 期望 getPool 不会被调用
        $this->poolManager->expects($this->never())->method('getPool');

        // 调用 pool 方法
        $this->aspect->pool($joinPoint);
    }

    /**
     * 测试 pool 方法在无法获取连接时抛出异常
     */
    public function testPoolThrowsExceptionWhenConnectionUnavailable(): void
    {
        $this->markTestSkipped('暂时跳过此测试，需要重写实现');
    }
}
