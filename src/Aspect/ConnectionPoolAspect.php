<?php

declare(strict_types=1);

namespace Tourze\Symfony\AopPoolBundle\Aspect;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Tourze\Symfony\Aop\Attribute\Aspect;
use Tourze\Symfony\Aop\Attribute\Before;
use Tourze\Symfony\Aop\Model\JoinPoint;
use Tourze\Symfony\AopPoolBundle\Attribute\ConnectionPool;
use Tourze\Symfony\AopPoolBundle\Exception\ServiceIdNotFoundException;
use Tourze\Symfony\AopPoolBundle\Exception\StopWorkerException;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionLifecycleHandler;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager;
use Tourze\Symfony\RuntimeContextBundle\Service\ContextServiceInterface;
use Utopia\Pools\Connection;

/**
 * 连接池的拦截实现
 * 直接替换instance对象实现连接池化
 */
#[Aspect]
#[WithMonologChannel(channel: 'connection_pool')]
class ConnectionPoolAspect implements ResetInterface
{
    /**
     * @var array<string, array<string, Connection<mixed>>> 按上下文和服务ID跟踪借出的连接
     */
    private array $borrowedConnections = [];

    public function __construct(
        private readonly ConnectionPoolManager $poolManager,
        private readonly ConnectionLifecycleHandler $lifecycleHandler,
        private readonly ContextServiceInterface $contextService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Redis连接池
     */
    #[Before(serviceTags: ['snc_redis.client'])]
    public function redis(JoinPoint $joinPoint): void
    {
        // ProxyManager代理Redis对象时可能会调用__destruct，需特殊处理
        if ('__destruct' === $joinPoint->getMethod()) {
            $joinPoint->setReturnEarly(true);
            $joinPoint->setReturnValue(null);

            return;
        }

        $this->pool($joinPoint);
    }

    /**
     * 主动声明需要连接池的服务
     */
    #[Before(statement: "serviceId starts with 'doctrine.dbal.' && serviceId ends with '_connection'")] // dbal数据库连接需要连接池
    #[Before(classAttribute: ConnectionPool::class)]
    public function pool(JoinPoint $joinPoint): void
    {
        // 以service为单位创建pool
        $serviceId = $joinPoint->getInternalServiceId();
        if (null === $serviceId) {
            throw new ServiceIdNotFoundException('无法获取服务ID');
        }

        $contextId = $this->contextService->getId();

        // 检查当前上下文是否已有借出的连接
        if (isset($this->borrowedConnections[$contextId][$serviceId])) {
            // 已借出过该服务的连接，直接复用
            $connection = $this->borrowedConnections[$contextId][$serviceId];
            $joinPoint->setInstance($connection->getResource());

            return;
        }

        // 获取连接池
        $pool = $this->poolManager->getPool($serviceId, $joinPoint);

        // 重试获取连接
        $retryAttempts = $this->getRetryAttempts();
        $errorList = [];

        while ($retryAttempts > 0) {
            try {
                // 从池中借出连接
                $connection = $this->poolManager->borrowConnection($serviceId, $pool);

                // 检查连接健康状态
                $this->lifecycleHandler->registerConnection($connection);
                $this->lifecycleHandler->checkConnection($connection);

                // 记录借出状态
                if (!isset($this->borrowedConnections[$contextId])) {
                    $this->borrowedConnections[$contextId] = [];
                }
                $this->borrowedConnections[$contextId][$serviceId] = $connection;

                // 记录借出日志
                $connectionId = $this->lifecycleHandler->getConnectionId($connection);
                $this->logger->info('借出连接', [
                    'serviceId' => $serviceId,
                    'contextId' => $contextId,
                    'hash' => $connectionId,
                    'poolAvailable' => $pool->count(),
                ]);

                // 设置替换实例
                $joinPoint->setInstance($connection->getResource());

                return;
            } catch (\Throwable $exception) {
                $errorList[] = $exception->getMessage();
                $this->logger->warning('获取连接失败，重试', [
                    'serviceId' => $serviceId,
                    'error' => $exception->getMessage(),
                    'remainingAttempts' => $retryAttempts - 1,
                ]);
                --$retryAttempts;
            }
        }

        // 所有重试都失败了，抛出异常
        $errorContext = [
            'serviceId' => $serviceId,
            'contextId' => $contextId,
            'currentBorrowedConnections' => count($this->borrowedConnections[$contextId] ?? []),
            'totalContexts' => count($this->borrowedConnections),
        ];

        throw new StopWorkerException('服务获取失败：' . $serviceId, context: array_merge($errorContext, ['errorList' => $errorList]));
    }

    /**
     * 归还当前上下文的所有连接
     */
    public function returnAll(): void
    {
        $contextId = $this->contextService->getId();
        $this->logger->info('重置连接池上下文', [
            'contextId' => $contextId,
        ]);

        if (!isset($this->borrowedConnections[$contextId])) {
            return;
        }

        foreach ($this->borrowedConnections[$contextId] as $serviceId => $conn) {
            $this->returnOne($contextId, $serviceId, $conn);
        }

        // 清理记录
        unset($this->borrowedConnections[$contextId]);

        // 定期清理连接池，避免资源泄漏
        $this->checkPoolHealth();
    }

    /**
     * 归还单个服务
     */
    /**
     * @param Connection<mixed> $conn
     */
    private function returnOne(string $contextId, string $serviceId, Connection $conn): void
    {
        $id = $this->lifecycleHandler->getConnectionId($conn);
        try {
            $pool = $this->poolManager->getPoolById($serviceId);

            try {
                // 检查连接是否健康
                $this->lifecycleHandler->checkConnection($conn);

                // 归还连接
                $this->poolManager->returnConnection($serviceId, $pool, $conn);

                $this->logger->info('归还连接', [
                    'serviceId' => $serviceId,
                    'contextId' => $contextId,
                    'hash' => $id,
                    'poolAvailable' => $pool->count(),
                ]);
            } catch (\Throwable $exception) {
                // 连接不健康，直接销毁
                $this->logger->warning('连接不健康，销毁', [
                    'serviceId' => $serviceId,
                    'contextId' => $contextId,
                    'hash' => $id,
                    'error' => $exception->getMessage(),
                ]);

                $this->poolManager->destroyConnection($serviceId, $pool, $conn);
            }
        } catch (\Throwable $e) {
            $this->logger->error('获取连接池失败', [
                'serviceId' => $serviceId,
                'contextId' => $contextId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function reset(): void
    {
        $this->returnAll();
    }

    /**
     * 定期检查并清理池中超时的连接
     */
    private function checkPoolHealth(): void
    {
        // 每100次请求随机触发一次连接池清理
        if (1 === mt_rand(1, 100)) {
            $this->poolManager->cleanup();
        }
    }

    /**
     * 获取重试次数
     */
    private function getRetryAttempts(): int
    {
        return intval($_ENV['SERVICE_POOL_GET_RETRY_ATTEMPTS'] ?? 5);
    }
}
