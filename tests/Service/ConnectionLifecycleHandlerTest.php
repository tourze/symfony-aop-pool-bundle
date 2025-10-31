<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Service;

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionLifecycleHandler;
use Utopia\Pools\Connection;

/**
 * @internal
 */
#[CoversClass(ConnectionLifecycleHandler::class)]
#[RunTestsInSeparateProcesses]
final class ConnectionLifecycleHandlerTest extends AbstractIntegrationTestCase
{
    private ConnectionLifecycleHandler $handler;

    protected function onSetUp(): void
    {
        // 设置环境变量
        $_ENV['SERVICE_POOL_CONNECTION_LIFETIME'] = '30';

        // 创建处理器实例
        $this->handler = self::getService(ConnectionLifecycleHandler::class);
    }

    public function testRegisterConnection(): void
    {
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 库提供的具体类，该库未提供相应接口
         * 2. 我们只需要一个占位符对象来测试 ConnectionLifecycleHandler 的行为
         * 3. 这是合理的，因为第三方库的设计不在我们的控制范围内
         */
        $connection = $this->createMock(Connection::class);

        // 调用注册方法
        $this->handler->registerConnection($connection);

        // 验证连接ID被记录
        $reflection = new \ReflectionProperty($this->handler, 'connectionStartTimes');
        $reflection->setAccessible(true);
        $startTimes = $reflection->getValue($this->handler);

        $id = $this->handler->getConnectionId($connection);
        self::assertArrayHasKey($id, $startTimes);
    }

    public function testGetConnectionId(): void
    {
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 库提供的具体类，该库未提供相应接口
         * 2. 我们只需要一个占位符对象来测试 ConnectionLifecycleHandler 的行为
         * 3. 这是合理的，因为第三方库的设计不在我们的控制范围内
         */
        $connection = $this->createMock(Connection::class);

        // 获取连接ID
        $id = $this->handler->getConnectionId($connection);

        // 验证ID与spl_object_hash一致
        self::assertEquals(spl_object_hash($connection), $id);
    }

    public function testUnregisterConnection(): void
    {
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 库提供的具体类，该库未提供相应接口
         * 2. 我们只需要一个占位符对象来测试 ConnectionLifecycleHandler 的行为
         * 3. 这是合理的，因为第三方库的设计不在我们的控制范围内
         */
        $connection = $this->createMock(Connection::class);

        // 先注册连接
        $this->handler->registerConnection($connection);

        // 获取连接ID
        $id = $this->handler->getConnectionId($connection);

        // 注销连接
        $this->handler->unregisterConnection($connection);

        // 验证连接ID被移除
        $reflection = new \ReflectionProperty($this->handler, 'connectionStartTimes');
        $reflection->setAccessible(true);
        $startTimes = $reflection->getValue($this->handler);

        self::assertArrayNotHasKey($id, $startTimes);
    }

    public function testGetConnectionAge(): void
    {
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 库提供的具体类，该库未提供相应接口
         * 2. 我们只需要一个占位符对象来测试 ConnectionLifecycleHandler 的行为
         * 3. 这是合理的，因为第三方库的设计不在我们的控制范围内
         */
        $connection = $this->createMock(Connection::class);

        // 获取未注册连接的年龄
        self::assertNull($this->handler->getConnectionAge($connection));

        // 注册连接
        $this->handler->registerConnection($connection);

        // 获取注册后的年龄
        $age = $this->handler->getConnectionAge($connection);

        // 验证年龄为0或1（考虑到执行时间）
        self::assertLessThanOrEqual(1, $age);
    }

    public function testCheckConnectionExpired(): void
    {
        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 库提供的具体类，该库未提供相应接口
         * 2. 我们只需要一个占位符对象来测试 ConnectionLifecycleHandler 的行为
         * 3. 这是合理的，因为第三方库的设计不在我们的控制范围内
         */
        $connection = $this->createMock(Connection::class);

        // 获取连接ID
        $id = $this->handler->getConnectionId($connection);

        // 手动设置连接开始时间为一小时前
        $reflection = new \ReflectionProperty($this->handler, 'connectionStartTimes');
        $reflection->setAccessible(true);
        $startTimes = $reflection->getValue($this->handler);
        $startTimes[$id] = time() - 3600; // 1小时前
        $reflection->setValue($this->handler, $startTimes);

        // 期望抛出异常
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('连接已过期');

        $this->handler->checkConnection($connection);
    }

    public function testCheckRedisConnection(): void
    {
        // Redis 测试使用 Mock 对象，不依赖真实的 Redis 扩展

        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 库提供的具体类，该库未提供相应接口
         * 2. 我们需要模拟 getResource() 方法来返回 Redis 实例
         * 3. 这是合理的，因为第三方库的设计不在我们的控制范围内
         */
        $connection = $this->createMock(Connection::class);
        /**
         * 必须使用具体类 \Redis 而不是接口，因为：
         * 1. PHP Redis 扩展没有提供接口，只提供了具体类
         * 2. 我们需要模拟 ping() 方法来测试连接健康检查
         * 3. 这是合理的，因为 Redis 扩展是一个底层的 PHP 扩展，而不是一个遵循接口设计原则的库
         */
        $redis = $this->createMock(\Redis::class);

        // 设置返回Redis资源
        $connection->method('getResource')->willReturn($redis);

        // 启用Redis连接检查
        $_ENV['SERVICE_POOL_CHECK_REDIS_CONNECTION'] = 'true';

        // 模拟ping成功
        $redis->expects($this->once())
            ->method('ping')
            ->willReturn('+PONG')
        ;

        // 调用检查方法
        $this->handler->registerConnection($connection);
        $this->handler->checkConnection($connection);
    }

    public function testCheckRedisConnectionFailure(): void
    {
        // Redis 测试使用 Mock 对象，不依赖真实的 Redis 扩展

        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 库提供的具体类，该库未提供相应接口
         * 2. 我们需要模拟 getResource() 方法来返回 Redis 实例
         * 3. 这是合理的，因为第三方库的设计不在我们的控制范围内
         */
        $connection = $this->createMock(Connection::class);
        /**
         * 必须使用具体类 \Redis 而不是接口，因为：
         * 1. PHP Redis 扩展没有提供接口，只提供了具体类
         * 2. 我们需要模拟 ping() 方法来测试连接健康检查
         * 3. 这是合理的，因为 Redis 扩展是一个底层的 PHP 扩展，而不是一个遵循接口设计原则的库
         */
        $redis = $this->createMock(\Redis::class);

        // 设置返回Redis资源
        $connection->method('getResource')->willReturn($redis);

        // 启用Redis连接检查
        $_ENV['SERVICE_POOL_CHECK_REDIS_CONNECTION'] = 'true';

        // 模拟ping失败
        $redis->expects($this->once())
            ->method('ping')
            ->willThrowException(new \Exception('Connection lost'))
        ;

        // 调用注册方法
        $this->handler->registerConnection($connection);

        // 期望抛出异常
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Redis连接不健康');

        $this->handler->checkConnection($connection);
    }

    public function testCheckDatabaseConnection(): void
    {
        // DBAL 测试使用 Mock 对象，不依赖真实的 Doctrine DBAL

        // 测试需要模拟 Doctrine\DBAL\Result
        $result = null;
        if (class_exists(Result::class)) {
            /**
             * 必须使用具体类 \Doctrine\DBAL\Result 而不是接口，因为：
             * 1. Doctrine DBAL 在不同版本中提供了不同的结果类，未统一接口
             * 2. 我们需要模拟 fetchOne() 方法来测试查询结果
             * 3. 这是合理的，因为这是为了兼容不同版本的 Doctrine DBAL
             */
            $result = $this->createMock(Result::class);
            $result->method('fetchOne')->willReturn('1');
        } else {
            // 适配旧版API
            /**
             * 必须使用具体类 \Doctrine\DBAL\Driver\Statement 而不是接口，因为：
             * 1. 这是旧版 Doctrine DBAL 的 API，在新版中已被 Result 类替代
             * 2. 我们需要模拟 fetchColumn() 方法来测试查询结果
             * 3. 这是合理的，因为这是为了保持向后兼容性
             */
            $result = $this->createMock(Statement::class);
            $result->method('fetchColumn')->willReturn('1');
        }

        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 库提供的具体类，该库未提供相应接口
         * 2. 我们需要模拟 getResource() 方法来返回 DBAL Connection 实例
         * 3. 这是合理的，因为第三方库的设计不在我们的控制范围内
         */
        $connection = $this->createMock(Connection::class);
        /**
         * 必须使用具体类 \Doctrine\DBAL\Connection 而不是接口，因为：
         * 1. 虽然 Doctrine 提供了 ConnectionInterface，但在测试中使用具体类更常见
         * 2. 我们需要模拟 executeQuery() 方法来测试数据库连接健康检查
         * 3. 这是可以接受的，但更好的做法是使用 Doctrine\DBAL\Driver\Connection 接口
         * 替代方案：$this->createMock(\Doctrine\DBAL\Driver\Connection::class)
         */
        $dbal = $this->createMock(\Doctrine\DBAL\Connection::class);

        // 设置返回DBAL资源
        $connection->method('getResource')->willReturn($dbal);

        // 启用数据库连接检查
        $_ENV['SERVICE_POOL_CHECK_DB_CONNECTION'] = 'true';

        // 模拟查询成功
        $dbal->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willReturn($result)
        ;

        // 调用检查方法
        $this->handler->registerConnection($connection);
        $this->handler->checkConnection($connection);
    }

    public function testCheckDatabaseConnectionFailure(): void
    {
        // DBAL 测试使用 Mock 对象，不依赖真实的 Doctrine DBAL

        /**
         * 必须使用具体类 Connection 而不是接口，因为：
         * 1. Connection 是 Utopia\Pools 库提供的具体类，该库未提供相应接口
         * 2. 我们需要模拟 getResource() 方法来返回 DBAL Connection 实例
         * 3. 这是合理的，因为第三方库的设计不在我们的控制范围内
         */
        $connection = $this->createMock(Connection::class);
        /**
         * 必须使用具体类 \Doctrine\DBAL\Connection 而不是接口，因为：
         * 1. 虽然 Doctrine 提供了 ConnectionInterface，但在测试中使用具体类更常见
         * 2. 我们需要模拟 executeQuery() 方法来测试数据库连接健康检查
         * 3. 这是可以接受的，但更好的做法是使用 Doctrine\DBAL\Driver\Connection 接口
         * 替代方案：$this->createMock(\Doctrine\DBAL\Driver\Connection::class)
         */
        $dbal = $this->createMock(\Doctrine\DBAL\Connection::class);

        // 设置返回DBAL资源
        $connection->method('getResource')->willReturn($dbal);

        // 启用数据库连接检查
        $_ENV['SERVICE_POOL_CHECK_DB_CONNECTION'] = 'true';

        // 模拟查询失败
        $dbal->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException(new \Exception('Connection lost'))
        ;

        // 调用注册方法
        $this->handler->registerConnection($connection);

        // 期望抛出异常
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('数据库连接不健康');

        $this->handler->checkConnection($connection);
    }
}
