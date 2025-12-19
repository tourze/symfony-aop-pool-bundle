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
final class ConnectionPoolAspectPoolTest extends AbstractIntegrationTestCase
{
    protected ConnectionPoolAspect $aspect;

    protected ContextServiceInterface $contextService;

    protected ConnectionPoolManager $poolManager;

    protected ConnectionLifecycleHandler $lifecycleHandler;

    protected function onSetUp(): void
    {
        // 重置 ENV 设置
        $_ENV['SERVICE_POOL_GET_RETRY_ATTEMPTS'] = '5'; // 使用合理的重试次数

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

    /**
     * 测试 pool 方法创建新的连接池
     *
     * 注意：虽然这是集成测试，但对于第三方库类（JoinPoint、Connection、Pool）
     * 仍然使用Mock，因为它们代表外部依赖或运行时动态创建的对象。
     */
    public function testPoolCreatesNewConnectionPool(): void
    {
        /**
         * 必须使用Mock的原因：
         * - JoinPoint 是 AOP 框架在运行时动态创建的对象
         * - 测试中无法创建真实的 JoinPoint 实例
         * - 这是集成测试中对外部框架类的标准处理方式
         */
        $joinPoint = $this->createMock(JoinPoint::class);
        $serviceId = 'test.service.pool.create';
        $joinPoint->expects($this->any())->method('getInternalServiceId')->willReturn($serviceId);
        $joinPoint->expects($this->once())->method('setInstance');

        // Mock InstanceService 返回的资源
        /**
         * 必须使用Mock的原因：
         * - 真实的服务实例（如Redis）需要实际的网络连接
         * - 这属于网络请求范畴，符合用户允许Mock的条件
         */
        $resource = $this->createMock(\stdClass::class);
        $joinPoint->method('getInstance')->willReturn($resource);

        // 获取当前上下文ID（使用真实的ContextService）
        $contextId = $this->contextService->getId();

        // 调用 pool 方法
        // 注意：这将使用真实的 ConnectionPoolManager 创建真实的 Pool
        // Pool 会使用 InstanceService 创建资源
        $this->aspect->pool($joinPoint);

        // 验证 borrowedConnections 是否正确记录
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayHasKey($contextId, $borrowedConnections);
        self::assertArrayHasKey($serviceId, $borrowedConnections[$contextId]);

        // 验证连接对象存在
        self::assertInstanceOf(Connection::class, $borrowedConnections[$contextId][$serviceId]);
    }

    /**
     * 测试 pool 方法重用现有连接
     */
    public function testPoolReusesExistingConnection(): void
    {
        /**
         * 必须使用Mock的原因：
         * - JoinPoint 是 AOP 框架在运行时动态创建的对象
         */
        $joinPoint = $this->createMock(JoinPoint::class);
        $serviceId = 'test.service.pool.reuse';
        $joinPoint->expects($this->any())->method('getInternalServiceId')->willReturn($serviceId);

        // 获取当前上下文ID
        $contextId = $this->contextService->getId();

        /**
         * 必须使用Mock的原因：
         * - Connection 代表实际的资源连接，避免真实网络请求
         */
        $connection = $this->createMock(Connection::class);
        $resource = new \stdClass();
        $connection->expects($this->once())->method('getResource')->willReturn($resource);

        // 设置已有连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [$serviceId => $connection],
        ]);

        // 期望 setInstance 会被调用一次
        $joinPoint->expects($this->once())->method('setInstance')->with($resource);

        // 调用 pool 方法
        $this->aspect->pool($joinPoint);
    }

    /**
     * 测试 redis 方法处理 __destruct 调用
     */
    public function testRedisHandlesDestructMethod(): void
    {
        /**
         * 必须使用Mock的原因：
         * - JoinPoint 是 AOP 框架在运行时动态创建的对象
         */
        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint->expects($this->once())->method('getMethod')->willReturn('__destruct');
        $joinPoint->expects($this->once())->method('setReturnEarly')->with(true);
        $joinPoint->expects($this->once())->method('setReturnValue')->with(null);

        // 期望 pool 方法不会被调用（通过验证 getInternalServiceId 不被调用）
        $joinPoint->expects($this->never())->method('getInternalServiceId');

        // 调用 redis 方法
        $this->aspect->redis($joinPoint);
    }

    /**
     * 测试 redis 方法处理非 __destruct 调用
     */
    public function testRedisHandlesNonDestructMethod(): void
    {
        /**
         * 必须使用Mock的原因：
         * - JoinPoint 是 AOP 框架在运行时动态创建的对象
         */
        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint->expects($this->once())->method('getMethod')->willReturn('get');
        $serviceId = 'redis.service.test';
        $joinPoint->expects($this->any())->method('getInternalServiceId')->willReturn($serviceId);
        $joinPoint->expects($this->once())->method('setInstance');

        // Mock 资源
        $resource = $this->createMock(\stdClass::class);
        $joinPoint->method('getInstance')->willReturn($resource);

        // 调用 redis 方法
        $this->aspect->redis($joinPoint);
    }

    /**
     * 测试 reset 方法
     */
    public function testReset(): void
    {
        // 获取上下文ID
        $contextId = $this->contextService->getId();
        $serviceId = 'test.service.reset';

        /**
         * 必须使用Mock的原因：
         * - Connection 代表实际的资源连接，避免真实网络请求
         */
        $connection = $this->createMock(Connection::class);

        // 设置已借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [$serviceId => $connection],
        ]);

        // 注册连接以便生命周期处理器可以追踪它
        $this->lifecycleHandler->registerConnection($connection);

        // 模拟 Pool - 需要为 ConnectionPoolManager 创建一个 Pool
        /**
         * 必须使用Mock的原因：
         * - Pool 是第三方库的类，且需要实际的资源工厂
         */
        $pool = $this->createMock(Pool::class);
        $pool->expects($this->once())->method('count')->willReturn(5);
        $pool->expects($this->once())->method('push')->with($connection);

        // 将 Pool 注入到 ConnectionPoolManager
        $pools = $this->getPrivateProperty($this->poolManager, 'pools');
        $pools[$serviceId] = $pool;
        $this->setPrivateProperty($this->poolManager, 'pools', $pools);

        // 初始化统计信息
        $reflection = new \ReflectionMethod($this->poolManager, 'initPoolStats');
        $reflection->setAccessible(true);
        $reflection->invoke($this->poolManager, $serviceId);

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
        // 设置空的借出连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', []);

        // 调用 returnAll 方法 - 应该不会抛出异常
        $this->aspect->returnAll();

        // 验证状态
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertEmpty($borrowedConnections);
    }

    /**
     * 测试 returnAll 方法处理多个连接
     */
    public function testReturnAllWithMultipleConnections(): void
    {
        // 获取上下文ID
        $contextId = $this->contextService->getId();

        /**
         * 必须使用Mock的原因：
         * - Connection 代表实际的资源连接，避免真实网络请求
         */
        $connection1 = $this->createMock(Connection::class);
        $connection2 = $this->createMock(Connection::class);

        $serviceId1 = 'test.service1';
        $serviceId2 = 'test.service2';

        // 设置已借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [
                $serviceId1 => $connection1,
                $serviceId2 => $connection2,
            ],
        ]);

        // 注册连接
        $this->lifecycleHandler->registerConnection($connection1);
        $this->lifecycleHandler->registerConnection($connection2);

        /**
         * 必须使用Mock的原因：
         * - Pool 是第三方库的类，且需要实际的资源工厂
         */
        $pool1 = $this->createMock(Pool::class);
        $pool1->expects($this->once())->method('count')->willReturn(3);
        $pool1->expects($this->once())->method('push')->with($connection1);

        $pool2 = $this->createMock(Pool::class);
        $pool2->expects($this->once())->method('count')->willReturn(4);
        $pool2->expects($this->once())->method('push')->with($connection2);

        // 将 Pool 注入到 ConnectionPoolManager
        $pools = [
            $serviceId1 => $pool1,
            $serviceId2 => $pool2,
        ];
        $this->setPrivateProperty($this->poolManager, 'pools', $pools);

        // 初始化统计信息
        $reflection = new \ReflectionMethod($this->poolManager, 'initPoolStats');
        $reflection->setAccessible(true);
        $reflection->invoke($this->poolManager, $serviceId1);
        $reflection->invoke($this->poolManager, $serviceId2);

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
        // 获取上下文ID
        $contextId = $this->contextService->getId();
        $serviceId = 'test.service.unhealthy';

        /**
         * 必须使用Mock的原因：
         * - Connection 代表实际的资源连接，避免真实网络请求
         * - 需要模拟连接健康检查失败的场景
         */
        $connection = $this->createMock(Connection::class);
        $connection->method('getResource')->willReturn($this->createMock(\stdClass::class));

        // 设置已借出的连接
        $this->setPrivateProperty($this->aspect, 'borrowedConnections', [
            $contextId => [$serviceId => $connection],
        ]);

        // 注册连接
        $this->lifecycleHandler->registerConnection($connection);

        /**
         * 必须使用Mock的原因：
         * - Pool 是第三方库的类，且需要实际的资源工厂
         */
        $pool = $this->createMock(Pool::class);
        $pool->expects($this->once())->method('destroy')->with($connection);
        $pool->expects($this->never())->method('push');

        // 将 Pool 注入到 ConnectionPoolManager
        $pools = [$serviceId => $pool];
        $this->setPrivateProperty($this->poolManager, 'pools', $pools);

        // 初始化统计信息
        $reflection = new \ReflectionMethod($this->poolManager, 'initPoolStats');
        $reflection->setAccessible(true);
        $reflection->invoke($this->poolManager, $serviceId);

        // 模拟连接健康检查失败
        // 我们需要让连接看起来过期或不健康
        // 通过设置一个非常早的注册时间来模拟连接过期
        $startTimes = $this->getPrivateProperty($this->lifecycleHandler, 'connectionStartTimes');
        $connectionId = $this->lifecycleHandler->getConnectionId($connection);
        $startTimes[$connectionId] = time() - 1000000; // 很久以前
        $this->setPrivateProperty($this->lifecycleHandler, 'connectionStartTimes', $startTimes);

        // 设置一个很短的生命周期，使连接过期
        $_ENV['SERVICE_POOL_CONNECTION_LIFETIME'] = '1';

        // 模拟随机清理（设置为不触发）
        mt_srand(50);

        // 调用 returnAll 方法
        $this->aspect->returnAll();

        // 验证连接已被清理
        $borrowedConnections = $this->getPrivateProperty($this->aspect, 'borrowedConnections');
        self::assertArrayNotHasKey($contextId, $borrowedConnections);
    }
}
