<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionLifecycleHandler;
use Utopia\Pools\Connection;

class ConnectionLifecycleHandlerTest extends TestCase
{
    private ConnectionLifecycleHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        // 设置环境变量
        $_ENV['SERVICE_POOL_CONNECTION_LIFETIME'] = '30';

        // 创建处理器实例
        $this->handler = new ConnectionLifecycleHandler();
    }

    protected function tearDown(): void
    {
        // 清除环境变量
        unset($_ENV['SERVICE_POOL_CONNECTION_LIFETIME']);
        parent::tearDown();
    }

    public function testRegisterConnection(): void
    {
        $connection = $this->createMock(Connection::class);

        // 调用注册方法
        $this->handler->registerConnection($connection);

        // 验证连接ID被记录
        $reflection = new \ReflectionProperty($this->handler, 'connectionStartTimes');
        $reflection->setAccessible(true);
        $startTimes = $reflection->getValue($this->handler);

        $id = $this->handler->getConnectionId($connection);
        $this->assertArrayHasKey($id, $startTimes);
    }

    public function testGetConnectionId(): void
    {
        $connection = $this->createMock(Connection::class);

        // 获取连接ID
        $id = $this->handler->getConnectionId($connection);

        // 验证ID与spl_object_hash一致
        $this->assertEquals(spl_object_hash($connection), $id);
    }

    public function testUnregisterConnection(): void
    {
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

        $this->assertArrayNotHasKey($id, $startTimes);
    }

    public function testGetConnectionAge(): void
    {
        $connection = $this->createMock(Connection::class);

        // 获取未注册连接的年龄
        $this->assertNull($this->handler->getConnectionAge($connection));

        // 注册连接
        $this->handler->registerConnection($connection);

        // 获取注册后的年龄
        $age = $this->handler->getConnectionAge($connection);

        // 验证年龄为0或1（考虑到执行时间）
        $this->assertLessThanOrEqual(1, $age);
    }

    public function testCheckConnectionExpired(): void
    {
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
        $this->expectExceptionMessage("连接已过期");

        $this->handler->checkConnection($connection);
    }

    public function testCheckRedisConnection(): void
    {
        // 跳过测试如果 Redis 类不存在
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('Redis class not found');
        }

        $connection = $this->createMock(Connection::class);
        $redis = $this->createMock(\Redis::class);

        // 设置返回Redis资源
        $connection->method('getResource')->willReturn($redis);

        // 启用Redis连接检查
        $_ENV['SERVICE_POOL_CHECK_REDIS_CONNECTION'] = true;

        // 模拟ping成功
        $redis->expects($this->once())
            ->method('ping')
            ->willReturn('+PONG');

        // 调用检查方法
        $this->handler->registerConnection($connection);
        $this->handler->checkConnection($connection);
    }

    public function testCheckRedisConnectionFailure(): void
    {
        // 跳过测试如果 Redis 类不存在
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('Redis class not found');
        }

        $connection = $this->createMock(Connection::class);
        $redis = $this->createMock(\Redis::class);

        // 设置返回Redis资源
        $connection->method('getResource')->willReturn($redis);

        // 启用Redis连接检查
        $_ENV['SERVICE_POOL_CHECK_REDIS_CONNECTION'] = true;

        // 模拟ping失败
        $redis->expects($this->once())
            ->method('ping')
            ->willThrowException(new \Exception('Connection lost'));

        // 调用注册方法
        $this->handler->registerConnection($connection);

        // 期望抛出异常
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Redis连接不健康");

        $this->handler->checkConnection($connection);
    }

    public function testCheckDatabaseConnection(): void
    {
        // 跳过测试如果 Doctrine\DBAL\Connection 类不存在
        if (!class_exists(\Doctrine\DBAL\Connection::class)) {
            $this->markTestSkipped('Doctrine\DBAL\Connection class not found');
        }

        // 测试需要模拟 Doctrine\DBAL\Result
        $result = null;
        if (class_exists(\Doctrine\DBAL\Result::class)) {
            $result = $this->createMock(\Doctrine\DBAL\Result::class);
            $result->method('fetchOne')->willReturn('1');
        } else {
            // 适配旧版API
            $result = $this->createMock(\Doctrine\DBAL\Driver\Statement::class);
            $result->method('fetchColumn')->willReturn('1');
        }

        $connection = $this->createMock(Connection::class);
        $dbal = $this->createMock(\Doctrine\DBAL\Connection::class);

        // 设置返回DBAL资源
        $connection->method('getResource')->willReturn($dbal);

        // 启用数据库连接检查
        $_ENV['SERVICE_POOL_CHECK_DB_CONNECTION'] = true;

        // 模拟查询成功
        $dbal->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willReturn($result);

        // 调用检查方法
        $this->handler->registerConnection($connection);
        $this->handler->checkConnection($connection);
    }

    public function testCheckDatabaseConnectionFailure(): void
    {
        // 跳过测试如果 Doctrine\DBAL\Connection 类不存在
        if (!class_exists(\Doctrine\DBAL\Connection::class)) {
            $this->markTestSkipped('Doctrine\DBAL\Connection class not found');
        }

        $connection = $this->createMock(Connection::class);
        $dbal = $this->createMock(\Doctrine\DBAL\Connection::class);

        // 设置返回DBAL资源
        $connection->method('getResource')->willReturn($dbal);

        // 启用数据库连接检查
        $_ENV['SERVICE_POOL_CHECK_DB_CONNECTION'] = true;

        // 模拟查询失败
        $dbal->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException(new \Exception('Connection lost'));

        // 调用注册方法
        $this->handler->registerConnection($connection);

        // 期望抛出异常
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("数据库连接不健康");

        $this->handler->checkConnection($connection);
    }
}
