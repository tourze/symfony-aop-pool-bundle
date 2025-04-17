<?php

namespace Tourze\Symfony\AopPoolBundle\Aspect;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ResetInterface;
use Tourze\Symfony\Aop\Attribute\Aspect;
use Tourze\Symfony\Aop\Attribute\Before;
use Tourze\Symfony\Aop\Model\JoinPoint;
use Tourze\Symfony\Aop\Service\ContextService;
use Tourze\Symfony\Aop\Service\InstanceService;
use Tourze\Symfony\AopPoolBundle\Attribute\ConnectionPool;
use Tourze\Symfony\AopPoolBundle\Exception\StopWorkerException;
use Utopia\Pools\Connection;
use Utopia\Pools\Pool;

/**
 * 连接池的拦截实现
 * 如果需要拦截，那我们直接替换instance对象
 */
#[Aspect]
class ConnectionPoolAspect implements EventSubscriberInterface, ResetInterface
{
    /**
     * @var array|Pool[]
     */
    private static array $pools = [];

    /**
     * @var Connection[][] 记录所有借出去的对象
     */
    private array $borrowed = [];

    private Logger $logger;

    private array $connStartTimes = [];

    public function __construct(
        private readonly ContextService $contextService,
        private readonly InstanceService $instanceService,
        private readonly ?KernelInterface $kernel = null,
    ) {
        $this->logger = new Logger('ConnectionPoolAspect');
        $this->logger->useLoggingLoopDetection(false);
        if (($_ENV['DEBUG_ConnectionPoolAspect'] ?? false) && null !== $this->kernel) {
            $this->logger->pushHandler(new StreamHandler($this->kernel->getCacheDir() . '/ConnectionPoolAspect.log', Level::Debug));
        }
    }

    /**
     * Redis连接池
     */
    #[Before(serviceTags: ['snc_redis.client'])]
    public function redis(JoinPoint $joinPoint): void
    {
        // ProxyManager代理Redis对象时，不知道为毛总是可能发生某个地方调用了__destruct
        // 我们只能先在这里做一次特殊处理
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
        // 以 service 为单位来创建 pool
        $serviceId = $joinPoint->getInternalServiceId();

        if (!isset(static::$pools[$serviceId])) {
            $pool = new Pool(
                $serviceId,
                $this->getPoolMaxSize(),
                function () use ($joinPoint) {
                    return $this->instanceService->create($joinPoint);
                },
            );

            // 重连3次，间隔1s
            $pool->setReconnectAttempts(3);
            $pool->setReconnectSleep(1);

            // 重试3次，间隔1s
            $pool->setRetryAttempts(3);
            $pool->setReconnectSleep(1);

            static::$pools[$serviceId] = $pool;
            $this->logger->debug('创建连接池', ['serviceId' => $serviceId]);
        }

        // 替换instance
        $contextId = $this->contextService->getId();
        if (!isset($this->borrowed[$contextId])) {
            $this->borrowed[$contextId] = [];
        }

        $pool = static::$pools[$serviceId];

        // 在极端情况下，我们整个连接池都塞满了对象，同时所有对象都已经过期，那下面就会出现获取不到对象的情形
        // 为了更合理获取，我们这里设置为连接池最大数量+1，那样子起码最后一次能获取成功吧
        $retryAttempts = $this->getPoolMaxSize() + 1;
        $errorList = [];

        while (!isset($this->borrowed[$contextId][$serviceId]) && $retryAttempts > 0) {
            $conn = $pool->pop();
            $id = $this->getObjectId($conn);
            $this->logger->debug('借出连接', [
                'serviceId' => $serviceId,
                'contextId' => $contextId,
                'tryTimes' => $retryAttempts,
                'hash' => $id,
                'count' => $pool->count(),
            ]);

            // 记录初始化对象时间
            if (!isset($this->connStartTimes[$id])) {
                $this->connStartTimes[$id] = time();
            }

            // echo "借出{$serviceId}: " . spl_object_hash($conn). ", 剩余" . static::$pools[$serviceId]->count() . "\n";
            // var_dump($contextId, $serviceId, spl_object_hash($conn->getResource()));
            // 循环直到可以借到一个不老的连接
            try {
                $this->checkConnection($conn);
            } catch (\Throwable $exception) {
                $errorList[] = $exception->getMessage();
                $this->logger->warning('连接过老，主动丢弃', [
                    'serviceId' => $serviceId,
                    'contextId' => $contextId,
                    'hash' => $id,
                ]);
                $this->destroyConnection($pool, $conn);
                --$retryAttempts;
                continue;
            }

            $this->borrowed[$contextId][$serviceId] = $conn;
        }

        if (!isset($this->borrowed[$contextId][$serviceId])) {
            throw new StopWorkerException('服务获取失败：' . $serviceId, context: ['serviceId' => $serviceId, 'contextId' => $contextId, 'retryAttempts' => $retryAttempts, 'errorList' => $errorList]);
        }

        $joinPoint->setInstance($this->borrowed[$contextId][$serviceId]->getResource());
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => ['reset', -10999],
            ConsoleEvents::TERMINATE => ['reset', -1024],
        ];
    }

    public function reset(): void
    {
        $this->logger->reset();

        $contextId = $this->contextService->getId();
        $this->logger->debug('重置连接池上下文', [
            'contextId' => $contextId,
        ]);

        foreach ($this->borrowed[$contextId] ?? [] as $serviceId => $conn) {
            $pool = static::$pools[$serviceId];
            $errorList = [];

            try {
                $this->checkConnection($conn);
            } catch (\Throwable $exception) {
                $errorList[] = $exception->getMessage();

                // 如果太老，就不还了，直接销毁
                $this->logger->debug('连接过老，不归还直接丢弃', [
                    'serviceId' => $serviceId,
                    'contextId' => $contextId,
                    'hash' => $this->getObjectId($conn),
                ]);
                $this->destroyConnection($pool, $conn);
                continue;
            }

            // 还回去
            $this->logger->debug('归还连接', [
                'serviceId' => $serviceId,
                'contextId' => $contextId,
                'hash' => $this->getObjectId($conn),
                'errorList' => $errorList,
                'count' => $pool->count(),
            ]);
            $pool->push($conn);
        }
        unset($this->borrowed[$contextId]);
        // TODO 过期的服务，我们要想个办法清除
        // echo memory_get_peak_usage() / 1024 / 1024;
        // echo "M\n";
    }

    private function getPoolMaxSize(): int
    {
        return intval($_ENV['SERVICE_POOL_DEFAULT_SIZE'] ?? 500);
    }

    private function destroyConnection(Pool $pool, Connection $connection): void
    {
        if (class_exists(\Redis::class) && $connection->getResource() instanceof \Redis) {
            $redis = $connection->getResource();
            /* @var \Redis $redis */
            try {
                $redis->close();
            } catch (\Throwable) {
            }
        }
        if ($connection->getResource() instanceof \Doctrine\DBAL\Connection) {
            $dbal = $connection->getResource();
            /* @var \Doctrine\DBAL\Connection $dbal */
            try {
                $dbal->close();
            } catch (\Throwable) {
            }
        }

        // 最后注销
        $id = $this->getObjectId($connection);
        unset($this->connStartTimes[$id]);
        $pool->destroy($connection);
    }

    /**
     * 获取对象的唯一ID
     * 要注意的是，如果一个对象被销毁了，那这里返回的id可能重复
     *
     * @see https://www.php.net/manual/zh/function.spl-object-hash.php
     */
    private function getObjectId(object $object): string
    {
        return spl_object_hash($object);
    }

    /**
     * 连接老化判断
     */
    private function checkConnection(Connection $connection): void
    {
        $id = $this->getObjectId($connection);

        // 不同类型的资源，有不同的过期策略

        if (class_exists(\Redis::class) && $connection->getResource() instanceof \Redis) {
            $startTime = $this->connStartTimes[$id] ?? null;
            if ($startTime && (time() - $startTime) >= 60) {
                throw new \Exception("Redis对象过老，应销毁，创建时间为{$startTime}");
            }

            //            $redis = $conn->getResource();
            //            /** @var \Redis $redis */
            //            try {
            //                $redis->ping();
            //                $redis->clearLastError(); // 清理上次错误信息，让连接正常
            //            } catch (\Throwable) {
            //                return true;
            //            }

            if (!$startTime) {
                $this->connStartTimes[$id] = time();
            }
        }

        if ($connection->getResource() instanceof \Doctrine\DBAL\Connection) {
            $startTime = $this->connStartTimes[$id] ?? null;
            if ($startTime && (time() - $startTime) >= 60) {
                throw new \Exception("PDO对象过老，应销毁，创建时间为{$startTime}");
            }
            if (!$startTime) {
                $this->connStartTimes[$id] = time();
            }
        }

        // 其他的我们不处理
    }
}
