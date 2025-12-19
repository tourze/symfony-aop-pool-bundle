<?php

declare(strict_types=1);

namespace Tourze\Symfony\AopPoolBundle\Service;

use Tourze\Symfony\AopPoolBundle\Exception\ConnectionExpiredException;
use Tourze\Symfony\AopPoolBundle\Exception\ConnectionUnhealthyException;
use Utopia\Pools\Connection;

/**
 * 连接生命周期处理器
 * 负责处理连接的创建、检查和销毁
 */
final class ConnectionLifecycleHandler
{
    /**
     * 连接生命周期(秒)
     */
    private int $connectionLifetime;

    /**
     * 连接开始时间记录
     *
     * @var array<string, int>
     */
    private array $connectionStartTimes = [];

    public function __construct()
    {
        $this->connectionLifetime = intval($_ENV['SERVICE_POOL_CONNECTION_LIFETIME'] ?? 60);
    }

    /**
     * 记录连接创建时间
     *
     * @param Connection<mixed> $connection
     */
    public function registerConnection(Connection $connection): void
    {
        $id = $this->getConnectionId($connection);

        if (!isset($this->connectionStartTimes[$id])) {
            $this->connectionStartTimes[$id] = time();
        }
    }

    /**
     * 检查连接是否健康/过期
     *
     * @param Connection<mixed> $connection
     *
     * @throws ConnectionExpiredException|ConnectionUnhealthyException 当连接过期或不健康时抛出
     */
    public function checkConnection(Connection $connection): void
    {
        $id = $this->getConnectionId($connection);
        $resource = $connection->getResource();
        $startTime = $this->connectionStartTimes[$id] ?? null;

        // 如果没有记录创建时间，先记录一下
        if (null === $startTime) {
            $this->connectionStartTimes[$id] = time();

            return;
        }

        // 检查连接是否过期
        $age = time() - $startTime;
        if ($age >= $this->connectionLifetime) {
            throw new ConnectionExpiredException("连接已过期，创建时间为{$startTime}，当前已使用{$age}秒");
        }

        // 根据资源类型执行特定的健康检查
        if (class_exists(\Redis::class) && $resource instanceof \Redis) {
            $this->checkRedisConnection($resource, $startTime);
        } elseif ($resource instanceof \Doctrine\DBAL\Connection) {
            $this->checkDatabaseConnection($resource, $startTime);
        }
    }

    /**
     * 检查Redis连接健康状态
     */
    private function checkRedisConnection(\Redis $redis, int $startTime): void
    {
        // 选择性激活：可以通过环境变量开启实际的ping检查
        if (($_ENV['SERVICE_POOL_CHECK_REDIS_CONNECTION'] ?? false) === 'true' || ($_ENV['SERVICE_POOL_CHECK_REDIS_CONNECTION'] ?? false) === '1') {
            try {
                $redis->ping();
                // 清理可能的错误状态
                $redis->clearLastError();
            } catch (\Throwable $e) {
                throw new ConnectionUnhealthyException('Redis连接不健康: ' . $e->getMessage());
            }
        }
    }

    /**
     * 检查数据库连接健康状态
     */
    private function checkDatabaseConnection(\Doctrine\DBAL\Connection $connection, int $startTime): void
    {
        // 选择性激活：通过环境变量开启实际的连接检查
        if (($_ENV['SERVICE_POOL_CHECK_DB_CONNECTION'] ?? false) === 'true' || ($_ENV['SERVICE_POOL_CHECK_DB_CONNECTION'] ?? false) === '1') {
            try {
                // 简单ping检查，不同DBAL版本可能API不同，尝试最通用的方式
                $connection->executeQuery('SELECT 1')->fetchOne();
            } catch (\Throwable $e) {
                throw new ConnectionUnhealthyException('数据库连接不健康: ' . $e->getMessage());
            }
        }
    }

    /**
     * 注销连接记录
     *
     * @param Connection<mixed> $connection
     */
    public function unregisterConnection(Connection $connection): void
    {
        $id = $this->getConnectionId($connection);
        unset($this->connectionStartTimes[$id]);
    }

    /**
     * 获取连接ID
     */
    public function getConnectionId(object $object): string
    {
        return spl_object_hash($object);
    }

    /**
     * 获取连接年龄(秒)
     *
     * @param Connection<mixed> $connection
     */
    public function getConnectionAge(Connection $connection): ?int
    {
        $id = $this->getConnectionId($connection);
        $startTime = $this->connectionStartTimes[$id] ?? null;

        if (null === $startTime) {
            return null;
        }

        return time() - $startTime;
    }
}
