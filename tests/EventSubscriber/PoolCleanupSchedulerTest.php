<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\EventSubscriber;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tourze\Symfony\AopPoolBundle\EventSubscriber\PoolCleanupScheduler;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager;

class PoolCleanupSchedulerTest extends TestCase
{
    private PoolCleanupScheduler $scheduler;
    private ConnectionPoolManager $poolManager;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // 模拟依赖
        $this->poolManager = $this->createMock(ConnectionPoolManager::class);
        $this->logger = $this->createMock(Logger::class);

        // 设置环境变量
        $_ENV['SERVICE_POOL_CLEANUP_INTERVAL'] = '10';

        // 创建调度器实例
        $this->scheduler = new PoolCleanupScheduler(
            $this->poolManager,
            $this->logger,
            true // debug mode
        );
    }

    protected function tearDown(): void
    {
        // 清除环境变量
        unset($_ENV['SERVICE_POOL_CLEANUP_INTERVAL']);
        parent::tearDown();
    }

    public function testScheduleCleanupFirstRun(): void
    {
        // 预期池管理器的cleanup方法会被调用
        $this->poolManager->expects($this->once())
            ->method('cleanup');

        // 预期日志记录
        $this->logger->expects($this->once())
            ->method('info')
            ->with('执行连接池清理完成', $this->anything());

        // 调用调度方法
        $this->scheduler->scheduleCleanup();
    }

    public function testScheduleCleanupWithinInterval(): void
    {
        // 首次运行
        $this->scheduler->scheduleCleanup();

        // 创建新的模拟对象
        $poolManager = $this->createMock(ConnectionPoolManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        // 创建一个新的调度器，与原始调度器共享上次运行时间
        $scheduler = new \ReflectionClass(PoolCleanupScheduler::class);
        $newScheduler = $scheduler->newInstance($poolManager, $logger, true);

        // 复制上次运行时间
        $lastRunTime = $this->getLastRunTime($this->scheduler);
        $this->setLastRunTime($newScheduler, $lastRunTime);

        // 预期在间隔内不应调用cleanup
        $poolManager->expects($this->never())
            ->method('cleanup');

        // 再次调用（应在间隔内）
        $newScheduler->scheduleCleanup();
    }

    public function testScheduleCleanupAfterInterval(): void
    {
        // 首次运行
        $this->scheduler->scheduleCleanup();

        // 创建新的模拟对象
        $poolManager = $this->createMock(ConnectionPoolManager::class);
        $logger = $this->createMock(LoggerInterface::class);

        // 创建一个新的调度器，与原始调度器共享上次运行时间
        $scheduler = new \ReflectionClass(PoolCleanupScheduler::class);
        $newScheduler = $scheduler->newInstance($poolManager, $logger, true);

        // 修改上次运行时间为20秒前（超过间隔）
        $this->setLastRunTime($newScheduler, time() - 20);

        // 预期再次调用cleanup
        $poolManager->expects($this->once())
            ->method('cleanup');

        // 预期日志记录
        $logger->expects($this->once())
            ->method('info')
            ->with('执行连接池清理完成', $this->anything());

        // 再次调用（应超过间隔）
        $newScheduler->scheduleCleanup();
    }

    public function testScheduleCleanupError(): void
    {
        // 模拟cleanup抛出异常
        $this->poolManager->expects($this->once())
            ->method('cleanup')
            ->willThrowException(new \Exception('Cleanup failed'));

        // 预期错误日志
        $this->logger->expects($this->once())
            ->method('error')
            ->with('连接池清理失败', $this->callback(function ($context) {
                return isset($context['error']) && $context['error'] === 'Cleanup failed'
                    && isset($context['trace']);
            }));

        // 调用调度方法
        $this->scheduler->scheduleCleanup();
    }

    public function testDefaultCleanupInterval(): void
    {
        // 清除环境变量
        unset($_ENV['SERVICE_POOL_CLEANUP_INTERVAL']);

        // 创建新的实例（不使用环境变量）
        $poolManager = $this->createMock(ConnectionPoolManager::class);
        $logger = $this->createMock(LoggerInterface::class);
        $scheduler = new PoolCleanupScheduler($poolManager, $logger, false);

        // 获取间隔属性
        $reflection = new \ReflectionProperty($scheduler, 'interval');
        $reflection->setAccessible(true);
        $interval = $reflection->getValue($scheduler);

        // 验证默认间隔为60秒
        $this->assertEquals(60, $interval);

        // 重新设置环境变量以供后续测试使用
        $_ENV['SERVICE_POOL_CLEANUP_INTERVAL'] = '10';
    }

    public function testDebugModeDisabled(): void
    {
        // 创建一个非调试模式的调度器
        $poolManager = $this->createMock(ConnectionPoolManager::class);
        $logger = $this->createMock(LoggerInterface::class);
        $scheduler = new PoolCleanupScheduler($poolManager, $logger, false);

        // 预期cleanup会被调用
        $poolManager->expects($this->once())
            ->method('cleanup');

        // 预期不会记录info日志（因为调试模式关闭）
        $logger->expects($this->never())
            ->method('info');

        // 调用调度方法
        $scheduler->scheduleCleanup();
    }

    /**
     * 获取上次运行时间
     */
    private function getLastRunTime(PoolCleanupScheduler $scheduler): int
    {
        $reflection = new \ReflectionProperty($scheduler, 'lastRunTime');
        $reflection->setAccessible(true);
        return $reflection->getValue($scheduler);
    }

    /**
     * 设置上次运行时间
     */
    private function setLastRunTime(PoolCleanupScheduler $scheduler, int $time): void
    {
        $reflection = new \ReflectionProperty($scheduler, 'lastRunTime');
        $reflection->setAccessible(true);
        $reflection->setValue($scheduler, $time);
    }
}
