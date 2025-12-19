<?php

declare(strict_types=1);

namespace Tourze\Symfony\AopPoolBundle\EventSubscriber;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager;

/**
 * 连接池定期清理任务
 * 定期执行连接池清理以回收资源
 */
#[WithMonologChannel(channel: 'connection_pool')]
final class PoolCleanupScheduler
{
    /**
     * 上次运行时间
     */
    private int $lastRunTime = 0;

    /**
     * 运行间隔(秒)
     */
    private int $interval;

    public function __construct(
        private readonly ConnectionPoolManager $poolManager,
        private readonly LoggerInterface $logger,
        private readonly ConnectionPoolAspect $poolAspect,
        #[Autowire(value: '%kernel.debug%')] private readonly bool $debug = false,
    ) {
        $this->interval = intval($_ENV['SERVICE_POOL_CLEANUP_INTERVAL'] ?? 60);
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class, priority: -10999)]
    #[AsEventListener(event: WorkerMessageHandledEvent::class, priority: -10999)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: -10999)]
    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -10999)]
    public function returnAll(): void
    {
        $this->poolAspect->returnAll();
    }

    /**
     * 触发清理任务，较低优先级，让其他正常处理先执行
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: -999)]
    public function scheduleCleanup(): void
    {
        $now = time();

        // 如果距离上次运行时间不足间隔，不执行
        if ($now - $this->lastRunTime < $this->interval) {
            return;
        }

        $this->lastRunTime = $now;

        try {
            // 执行连接池清理
            $this->poolManager->cleanup();

            if ($this->debug) {
                $this->logger->info('执行连接池清理完成', [
                    'timestamp' => $now,
                    'interval' => $this->interval,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('连接池清理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
