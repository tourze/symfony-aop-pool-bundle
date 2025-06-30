<?php

namespace Tourze\Symfony\AopPoolBundle\Service;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\Service\ResetInterface;
use Tourze\BacktraceHelper\ExceptionPrinter;
use Tourze\Symfony\Aop\Model\JoinPoint;
use Tourze\Symfony\AopPoolBundle\Exception\PoolNotFoundException;
use Tourze\Symfony\Aop\Service\InstanceService;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

/**
 * 连接池管理器
 * 负责创建、获取和维护连接池
 */
#[Autoconfigure(public: true)]
#[WithMonologChannel(channel: 'connection_pool')]
class ConnectionPoolManager implements ResetInterface
{
    /**
     * @var array|Pool[]
     */
    private array $pools = [];

    /**
     * @var array 记录每个连接池的统计信息
     */
    private array $poolStats = [];

    public function __construct(
        private readonly InstanceService $instanceService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * 获取或创建连接池
     */
    public function getPool(string $serviceId, JoinPoint $joinPoint): Pool
    {
        if (!isset($this->pools[$serviceId])) {
            $pool = new Pool(
                $serviceId,
                $this->getPoolMaxSize(),
                function () use ($joinPoint) {
                    return $this->instanceService->create($joinPoint);
                },
            );

            // 重连配置
            $pool->setReconnectAttempts($this->getReconnectAttempts());
            $pool->setReconnectSleep($this->getReconnectSleep());

            // 重试配置
            $pool->setRetryAttempts($this->getRetryAttempts());
            $pool->setRetrySleep($this->getRetrySleep());

            $this->pools[$serviceId] = $pool;
            $this->initPoolStats($serviceId);
            $this->logger->info('创建连接池', ['serviceId' => $serviceId]);
        }

        return $this->pools[$serviceId];
    }

    /**
     * 从池中获取连接
     */
    public function borrowConnection(string $serviceId, Pool $pool): Connection
    {
        $connection = $pool->pop();

        // 更新统计信息
        $this->updatePoolStats($serviceId, 'borrowed', 1);
        $this->updatePoolStats($serviceId, 'available', -1);

        return $connection;
    }

    /**
     * 归还连接到池
     */
    public function returnConnection(string $serviceId, Pool $pool, Connection $connection): void
    {
        $pool->push($connection);

        // 更新统计信息
        $this->updatePoolStats($serviceId, 'borrowed', -1);
        $this->updatePoolStats($serviceId, 'available', 1);
    }

    /**
     * 销毁连接
     */
    public function destroyConnection(string $serviceId, Pool $pool, Connection $connection): void
    {
        // 销毁前先关闭连接
        $this->closeResource($connection);

        $pool->destroy($connection);

        // 更新统计信息
        $this->updatePoolStats($serviceId, 'borrowed', -1);
        $this->updatePoolStats($serviceId, 'destroyed', 1);
    }

    /**
     * 关闭资源连接
     */
    private function closeResource(Connection $connection): void
    {
        if (class_exists(\Redis::class) && $connection->getResource() instanceof \Redis) {
            $redis = $connection->getResource();
            /* @var \Redis $redis */
            try {
                $redis->close();
            } catch (\Throwable $e) {
                $this->logger->warning('Redis连接关闭失败', [
                    'error' => $e->getMessage(),
                    'hash' => spl_object_hash($connection),
                ]);
            }
        }

        if ($connection->getResource() instanceof \Doctrine\DBAL\Connection) {
            $dbal = $connection->getResource();
            /* @var \Doctrine\DBAL\Connection $dbal */
            try {
                $dbal->close();
            } catch (\Throwable $e) {
                $this->logger->warning('数据库连接关闭失败', [
                    'error' => $e->getMessage(),
                    'hash' => spl_object_hash($connection),
                ]);
            }
        }
    }

    /**
     * 清理所有过期池和连接
     * 定期调用以避免资源泄漏
     */
    public function cleanup(): void
    {
        foreach ($this->pools as $serviceId => $pool) {
            $this->logger->info('清理连接池', [
                'serviceId' => $serviceId,
                'stats' => $this->poolStats[$serviceId] ?? [],
            ]);

            // 尝试清理池中可能的无效连接
            $this->cleanupPool($pool, $serviceId);
        }
    }

    /**
     * 清理特定池中的过期或无效连接
     */
    private function cleanupPool(Pool $pool, string $serviceId): void
    {
        // 这里可以添加更多的池清理逻辑
        // 例如检查连接健康状态、移除长时间未使用的连接等
        try {
            // Utopia\Pools\Pool 可能没有 cleanup 方法，使用其他方式清理
            // 定期销毁并重建部分连接以避免资源耗尽
            $this->performConnectionHealthCheck($pool, $serviceId);

            $this->logger->debug('连接池清理完成', [
                'serviceId' => $serviceId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('连接池清理异常', [
                'serviceId' => $serviceId,
                'error' => ExceptionPrinter::exception($e),
            ]);
        }
    }

    /**
     * 执行连接健康检查
     * 随机销毁部分连接以刷新池
     */
    private function performConnectionHealthCheck(Pool $pool, string $serviceId): void
    {
        // 当前可用连接数
        $availableCount = $pool->count();

        // 如果池中连接数量过少，不执行清理
        if ($availableCount < 5) {
            return;
        }

        // 随机销毁一小部分连接（约5%），避免资源长期占用
        $connectionsToDestroy = max(1, intval($availableCount * 0.05));

        for ($i = 0; $i < $connectionsToDestroy; $i++) {
            try {
                // 获取连接
                $connection = $pool->pop();

                // 销毁连接
                $this->closeResource($connection);
                $pool->destroy($connection);

                // 更新统计信息
                $this->updatePoolStats($serviceId, 'destroyed', 1);

                $this->logger->debug('清理时销毁连接', [
                    'serviceId' => $serviceId,
                    'remainingConnections' => $pool->count(),
                ]);
            } catch (\Throwable $e) {
                // 忽略单个连接销毁失败
                $this->logger->debug('清理时销毁连接失败', [
                    'serviceId' => $serviceId,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }
    }

    public function reset(): void
    {
        // 只有健康检查，不需要额外操作
    }

    /**
     * 初始化连接池统计
     */
    private function initPoolStats(string $serviceId): void
    {
        $this->poolStats[$serviceId] = [
            'created' => time(),
            'borrowed' => 0,  // 已借出的连接数
            'available' => $this->getPoolMaxSize(), // 可用连接数
            'destroyed' => 0, // 已销毁的连接数
        ];
    }

    /**
     * 更新连接池统计
     */
    private function updatePoolStats(string $serviceId, string $key, int $delta): void
    {
        if (!isset($this->poolStats[$serviceId])) {
            $this->initPoolStats($serviceId);
        }

        if (isset($this->poolStats[$serviceId][$key])) {
            $this->poolStats[$serviceId][$key] += $delta;
        } else {
            $this->poolStats[$serviceId][$key] = $delta;
        }

        // 记录最后更新时间
        $this->poolStats[$serviceId]['lastUpdated'] = time();
    }

    /**
     * 获取池最大大小
     */
    private function getPoolMaxSize(): int
    {
        return intval($_ENV['SERVICE_POOL_DEFAULT_SIZE'] ?? 500);
    }

    /**
     * 获取重连尝试次数
     */
    private function getReconnectAttempts(): int
    {
        return intval($_ENV['SERVICE_POOL_RECONNECT_ATTEMPTS'] ?? 3);
    }

    /**
     * 获取重连间隔(秒)
     */
    private function getReconnectSleep(): int
    {
        return intval($_ENV['SERVICE_POOL_RECONNECT_SLEEP'] ?? 1);
    }

    /**
     * 获取重试尝试次数
     */
    private function getRetryAttempts(): int
    {
        return intval($_ENV['SERVICE_POOL_RETRY_ATTEMPTS'] ?? 3);
    }

    /**
     * 获取重试间隔(秒)
     */
    private function getRetrySleep(): int
    {
        return intval($_ENV['SERVICE_POOL_RETRY_SLEEP'] ?? 1);
    }

    /**
     * 获取池统计信息
     */
    public function getPoolStats(): array
    {
        return $this->poolStats;
    }

    /**
     * 根据服务ID获取连接池
     * 如果连接池不存在，抛出异常
     *
     * @throws PoolNotFoundException 如果找不到指定的连接池
     */
    public function getPoolById(string $serviceId): Pool
    {
        if (!isset($this->pools[$serviceId])) {
            throw new PoolNotFoundException("找不到服务ID为 {$serviceId} 的连接池");
        }

        return $this->pools[$serviceId];
    }
}
