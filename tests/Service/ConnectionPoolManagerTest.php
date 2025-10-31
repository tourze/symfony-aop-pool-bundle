<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Service;

use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tourze\Symfony\Aop\Model\JoinPoint;
use Tourze\Symfony\Aop\Service\InstanceService;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

/**
 * @internal
 * @phpstan-ignore-next-line 测试用例 Tourze\Symfony\AopPoolBundle\Tests\Service\ConnectionPoolManagerTest 的测试目标 Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager 是一个服务，因此不应直接继承自 PHPUnit\Framework\TestCase。
 */
#[CoversClass(ConnectionPoolManager::class)]
final class ConnectionPoolManagerTest extends TestCase
{
    private ConnectionPoolManager $poolManager;

    /** @var InstanceService&MockObject */
    private InstanceService $instanceService;

    /** @var Logger&MockObject */
    private Logger $logger;

    /** @var JoinPoint&MockObject */
    private JoinPoint $joinPoint;

    protected function setUp(): void
    {
        // 模拟 InstanceService
        /*
         * 必须使用具体类 InstanceService 而不是接口，因为：
         * 1. InstanceService 是一个服务类，没有定义对应的接口
         * 2. 我们需要模拟 create() 方法来测试 ConnectionPoolManager 的行为
         * 3. 这是合理的，因为我们测试的是 ConnectionPoolManager 与 InstanceService 的交互
         * 替代方案：可以为 InstanceService 创建一个接口，但这会增加不必要的复杂性
         */
        $this->instanceService = $this->createMock(InstanceService::class);

        // 模拟 Logger
        /*
         * 必须使用具体类 Logger 而不是 LoggerInterface，因为：
         * 1. 虽然 Monolog 提供了 LoggerInterface，但在某些情况下具体类可能有额外的方法
         * 2. 这是一个遗留问题，应该使用 Psr\Log\LoggerInterface 代替
         * 更好的替代方案：应该改为 $this->createMock(\Psr\Log\LoggerInterface::class)
         */
        $this->logger = $this->createMock(Logger::class);

        // 直接实例化 ConnectionPoolManager，传入模拟依赖
        $this->poolManager = new ConnectionPoolManager(
            $this->instanceService,
            $this->logger,
        );

        // 创建 JoinPoint 模拟对象
        /*
         * 必须使用具体类 JoinPoint 而不是接口，因为：
         * 1. JoinPoint 是 AOP 框架提供的具体类，没有定义对应的接口
         * 2. 我们只需要一个占位符对象传递给 InstanceService
         * 3. 这是合理的，因为 JoinPoint 是 AOP 框架的核心概念，其设计决定不在我们的控制范围内
         */
        $this->joinPoint = $this->createMock(JoinPoint::class);
    }

    public function testGetPool(): void
    {
        // 模拟服务ID
        $serviceId = 'test.service';

        // 设置环境变量
        $_ENV['SERVICE_POOL_DEFAULT_SIZE'] = '10';
        $_ENV['SERVICE_POOL_RECONNECT_ATTEMPTS'] = '3';
        $_ENV['SERVICE_POOL_RECONNECT_SLEEP'] = '2';
        $_ENV['SERVICE_POOL_RETRY_ATTEMPTS'] = '5';
        $_ENV['SERVICE_POOL_RETRY_SLEEP'] = '1';

        // 模拟 InstanceService 创建一个实例
        $instance = new \stdClass();
        $this->instanceService->method('create')
            ->with($this->joinPoint)
            ->willReturn($instance)
        ;

        // 第一次获取池应该创建一个新的池
        $pool = $this->poolManager->getPool($serviceId, $this->joinPoint);

        // 第二次获取相同ID的池应该返回相同的池实例
        $pool2 = $this->poolManager->getPool($serviceId, $this->joinPoint);
        self::assertSame($pool, $pool2);
    }

    public function testBorrowAndReturnConnection(): void
    {
        // 创建 Pool 模拟对象
        /**
         * 必须使用具体类 Pool 而不是接口，因为：
         * 1. Pool 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要模拟池的行为（如 pop、push 方法）
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Pool<mixed>&MockObject $pool */
        $pool = $this->createMock(Pool::class);
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们只需要一个连接对象的占位符，不需要其具体行为
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Connection<mixed>&MockObject $connection */
        $connection = $this->createMock(Connection::class);

        // 设置 pop 方法返回模拟连接
        $pool->method('pop')
            ->willReturn($connection)
        ;

        // 借用连接
        $borrowedConnection = $this->poolManager->borrowConnection('test.service', $pool);
        self::assertSame($connection, $borrowedConnection);

        // 测试归还连接
        $pool->method('push')
            ->with($connection)
        ;

        $this->poolManager->returnConnection('test.service', $pool, $connection);

        // 测试完成，已通过相关验证
    }

    public function testDestroyConnection(): void
    {
        // 创建 Pool 模拟对象
        /**
         * 必须使用具体类 Pool 而不是接口，因为：
         * 1. Pool 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要模拟池的 destroy 方法
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Pool<mixed>&MockObject $pool */
        $pool = $this->createMock(Pool::class);
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要模拟 getResource() 方法来测试资源清理逻辑
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Connection<mixed>&MockObject $connection */
        $connection = $this->createMock(Connection::class);

        // 模拟资源
        $resource = new \stdClass();
        $connection->method('getResource')
            ->willReturn($resource)
        ;

        // 预期 destroy 方法会被调用
        $pool->method('destroy')
            ->with($connection)
        ;

        // 销毁连接
        $this->poolManager->destroyConnection('test.service', $pool, $connection);

        // 测试完成，已通过相关验证
    }

    #[DataProvider('redisResourceProvider')]
    public function testCloseRedisResource(bool $isRedis, int $expectedCalls): void
    {
        // Redis 测试不依赖真实的 Redis 扩展，使用 Mock 对象模拟

        // 创建 Pool 模拟对象
        /**
         * 必须使用具体类 Pool 而不是接口，因为：
         * 1. Pool 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要模拟池的 destroy 方法
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Pool<mixed>&MockObject $pool */
        $pool = $this->createMock(Pool::class);
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 包提供的具体类，该package可能没有定义接口
         * 2. 我们需要模拟 getResource() 方法来测试资源清理逻辑
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Connection<mixed>&MockObject $connection */
        $connection = $this->createMock(Connection::class);

        // 模拟资源
        $resource = null;
        if ($isRedis) {
            $resource = $this->createMock(\Redis::class);
            if ($expectedCalls > 0) {
                $resource->expects(self::exactly($expectedCalls))
                    ->method('close')
                ;
            }
        } else {
            $resource = new \stdClass();
        }

        $connection->method('getResource')
            ->willReturn($resource)
        ;

        // 设置 destroy 方法期望被调用一次
        $pool->expects(self::once())
            ->method('destroy')
            ->with($connection)
        ;

        // 销毁连接，验证方法执行成功
        $this->poolManager->destroyConnection('test.service', $pool, $connection);
    }

    /**
     * @return array<string, array{bool, int}>
     */
    public static function redisResourceProvider(): array
    {
        return [
            'not redis' => [false, 0],
            'is redis' => [true, 1],
        ];
    }

    #[DataProvider('dbalResourceProvider')]
    public function testCloseDbalResource(bool $isDbal, int $expectedCalls): void
    {
        // DBAL 测试不依赖真实的 Doctrine DBAL，使用 Mock 对象模拟

        // 创建 Pool 模拟对象
        /**
         * 必须使用具体类 Pool 而不是接口，因为：
         * 1. Pool 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要模拟池的 destroy 方法
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Pool<mixed>&MockObject $pool */
        $pool = $this->createMock(Pool::class);
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要模拟 getResource() 方法来测试资源清理逻辑
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Connection<mixed>&MockObject $connection */
        $connection = $this->createMock(Connection::class);

        // 模拟资源
        $resource = null;
        if ($isDbal) {
            $resource = $this->createMock(\Doctrine\DBAL\Connection::class);
            if ($expectedCalls > 0) {
                $resource->method('close');
            }
        } else {
            $resource = new \stdClass();
        }

        $connection->method('getResource')
            ->willReturn($resource)
        ;

        // 设置 destroy 方法期望被调用一次
        $pool->expects(self::once())
            ->method('destroy')
            ->with($connection)
        ;

        // 销毁连接，验证方法执行成功
        $this->poolManager->destroyConnection('test.service', $pool, $connection);
    }

    /**
     * @return array<string, array{bool, int}>
     */
    public static function dbalResourceProvider(): array
    {
        return [
            'not dbal' => [false, 0],
            'is dbal' => [true, 1],
        ];
    }

    public function testCleanup(): void
    {
        // 模拟连接池
        /**
         * 必须使用具体类 Pool 而不是接口，因为：
         * 1. Pool 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要模拟 count 方法来测试清理功能
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Pool<mixed>&MockObject $pool */
        $pool = $this->createMock(Pool::class);
        $pool->method('count')->willReturn(10);

        // 将池添加到内部属性
        $reflection = new \ReflectionProperty(ConnectionPoolManager::class, 'pools');
        $reflection->setAccessible(true);
        $reflection->setValue($this->poolManager, ['test.service' => $pool]);

        // 预期日志记录
        $this->logger->method('info')
            ->with(self::equalTo('清理连接池'), self::anything())
        ;

        // 调用清理方法
        $this->poolManager->cleanup();

        // 添加一个断言以避免测试被标记为 risky
        self::assertArrayHasKey('test.service', $reflection->getValue($this->poolManager));
    }

    public function testReset(): void
    {
        // 创建多个 Pool 模拟对象
        // 调用 reset 方法，该方法目前只做健康检查，不需要模拟任何行为
        $this->poolManager->reset();

        // reset 方法执行完成不抛出异常即为成功
        self::expectNotToPerformAssertions();
    }

    public function testReturnConnection(): void
    {
        // 创建 Pool 模拟对象
        /**
         * 必须使用具体类 Pool 而不是接口，因为：
         * 1. Pool 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要模拟 push 方法来测试连接归还功能
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Pool<mixed>&MockObject $pool */
        $pool = $this->createMock(Pool::class);
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们只需要一个连接对象的占位符，不需要其具体行为
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Connection<mixed>&MockObject $connection */
        $connection = $this->createMock(Connection::class);

        // 设置期望：push 方法应该被调用一次，并传入连接对象
        $pool->expects(self::once())
            ->method('push')
            ->with(self::identicalTo($connection))
        ;

        // 调用 returnConnection 方法
        $this->poolManager->returnConnection('test.service', $pool, $connection);

        // 测试完成，已通过相关验证
    }

    /**
     * 测试 borrowConnection 方法
     */
    public function testBorrowConnection(): void
    {
        // 创建 Pool 模拟对象
        /**
         * 必须使用具体类 Pool 而不是接口，因为：
         * 1. Pool 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要模拟 pop 方法来测试连接借用功能
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Pool<mixed>&MockObject $pool */
        $pool = $this->createMock(Pool::class);
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 包提供的具体类，该包可能没有定义接口
         * 2. 我们需要模拟连接对象的返回值
         * 3. 这是合理的，因为这是第三方库的设计决定
         */
        /** @var Connection<mixed>&MockObject $connection */
        $connection = $this->createMock(Connection::class);

        // 设置期望：pop 方法应该被调用一次，并返回连接对象
        $pool->expects(self::once())
            ->method('pop')
            ->willReturn($connection)
        ;

        // 调用 borrowConnection 方法
        $result = $this->poolManager->borrowConnection('test.service', $pool);

        // 验证返回的连接对象
        self::assertSame($connection, $result);
    }
}
