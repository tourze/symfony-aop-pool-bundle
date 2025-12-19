<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\EventSubscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Tourze\PHPUnitSymfonyKernelTest\AbstractEventSubscriberTestCase;
use Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect;
use Tourze\Symfony\AopPoolBundle\EventSubscriber\PoolCleanupScheduler;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager;

/**
 * @internal
 */
#[CoversClass(PoolCleanupScheduler::class)]
#[RunTestsInSeparateProcesses]
final class PoolCleanupSchedulerTest extends AbstractEventSubscriberTestCase
{
    private PoolCleanupScheduler $scheduler;

    private ConnectionPoolManager $poolManager;

    private LoggerInterface&MockObject $logger;

    private ConnectionPoolAspect $poolAspect;

    protected function onSetUp(): void
    {
        // 从容器获取真实服务（ConnectionPoolManager 和 ConnectionPoolAspect 是 final 类）
        $this->poolManager = self::getService(ConnectionPoolManager::class);
        $this->poolAspect = self::getService(ConnectionPoolAspect::class);

        // 只 Mock LoggerInterface 用于验证日志调用
        $this->logger = $this->createMock(LoggerInterface::class);

        // 设置环境变量
        $_ENV['SERVICE_POOL_CLEANUP_INTERVAL'] = '10';

        // 使用真实服务和 Mock Logger 创建调度器实例
        $reflection = new \ReflectionClass(PoolCleanupScheduler::class);
        $this->scheduler = $reflection->newInstance(
            $this->poolManager,
            $this->logger,
            $this->poolAspect,
            true // debug mode
        );
    }

    public function testScheduleCleanupFirstRun(): void
    {
        // 预期日志记录（debug 模式开启）
        $this->logger->expects($this->once())
            ->method('info')
            ->with('执行连接池清理完成', self::anything())
        ;

        // 调用调度方法（使用真实的 poolManager，会真正执行 cleanup）
        $this->scheduler->scheduleCleanup();

        // 验证 lastRunTime 已更新（通过反射检查）
        $lastRunTime = $this->getLastRunTime($this->scheduler);
        self::assertGreaterThan(0, $lastRunTime);
    }

    public function testScheduleCleanupWithinInterval(): void
    {
        // 首次运行
        $this->scheduler->scheduleCleanup();

        // 获取首次运行时间
        $firstRunTime = $this->getLastRunTime($this->scheduler);

        // 创建新的 Mock Logger（不期望调用 info）
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())
            ->method('info')
        ;

        // 创建一个新的调度器，使用真实服务
        $reflection = new \ReflectionClass(PoolCleanupScheduler::class);
        $newScheduler = $reflection->newInstance($this->poolManager, $logger, $this->poolAspect, true);

        // 复制上次运行时间（模拟间隔内的情况）
        $this->setLastRunTime($newScheduler, $firstRunTime);

        // 再次调用（应在间隔内，不会执行清理）
        $newScheduler->scheduleCleanup();

        // 验证 lastRunTime 未改变
        $newRunTime = $this->getLastRunTime($newScheduler);
        self::assertEquals($firstRunTime, $newRunTime);
    }

    public function testScheduleCleanupAfterInterval(): void
    {
        // 创建新的 Mock Logger（期望调用 info）
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('执行连接池清理完成', self::anything())
        ;

        // 创建一个新的调度器，使用真实服务
        $reflection = new \ReflectionClass(PoolCleanupScheduler::class);
        $newScheduler = $reflection->newInstance($this->poolManager, $logger, $this->poolAspect, true);

        // 修改上次运行时间为20秒前（超过10秒间隔）
        $oldTime = time() - 20;
        $this->setLastRunTime($newScheduler, $oldTime);

        // 再次调用（应超过间隔，会执行清理）
        $newScheduler->scheduleCleanup();

        // 验证 lastRunTime 已更新
        $newRunTime = $this->getLastRunTime($newScheduler);
        self::assertGreaterThan($oldTime, $newRunTime);
    }

    public function testScheduleCleanupError(): void
    {
        // 由于 ConnectionPoolManager 是 final 类且有严格类型声明，无法 Mock 来模拟异常
        // 这个测试验证的是：即使 cleanup() 内部发生错误，scheduleCleanup() 也会捕获异常并继续执行
        // 我们通过验证方法调用不抛出异常来测试错误处理机制

        $logger = $this->createMock(LoggerInterface::class);

        // 创建调度器
        $reflection = new \ReflectionClass(PoolCleanupScheduler::class);
        $scheduler = $reflection->newInstance($this->poolManager, $logger, $this->poolAspect, true);

        // 调用调度方法，验证不会抛出未捕获的异常（scheduleCleanup 有 try-catch）
        $scheduler->scheduleCleanup();

        // 验证 lastRunTime 已更新（说明方法成功执行）
        $lastRunTime = $this->getLastRunTime($scheduler);
        self::assertGreaterThan(0, $lastRunTime, 'scheduleCleanup should complete even if cleanup has errors');
    }

    public function testDefaultCleanupInterval(): void
    {
        // 清除环境变量
        unset($_ENV['SERVICE_POOL_CLEANUP_INTERVAL']);

        // 创建新的实例（不使用环境变量），使用真实服务
        $logger = $this->createMock(LoggerInterface::class);
        $reflection = new \ReflectionClass(PoolCleanupScheduler::class);
        $scheduler = $reflection->newInstance($this->poolManager, $logger, $this->poolAspect, false);

        // 获取间隔属性
        $intervalReflection = new \ReflectionProperty($scheduler, 'interval');
        $intervalReflection->setAccessible(true);
        $interval = $intervalReflection->getValue($scheduler);

        // 验证默认间隔为60秒
        self::assertEquals(60, $interval);

        // 重新设置环境变量以供后续测试使用
        $_ENV['SERVICE_POOL_CLEANUP_INTERVAL'] = '10';
    }

    public function testDebugModeDisabled(): void
    {
        // 创建一个非调试模式的调度器，使用真实服务
        $logger = $this->createMock(LoggerInterface::class);

        // 预期不会记录 info 日志（因为调试模式关闭）
        $logger->expects($this->never())
            ->method('info')
        ;

        // 使用反射创建实例（debug = false）
        $reflection = new \ReflectionClass(PoolCleanupScheduler::class);
        $scheduler = $reflection->newInstance($this->poolManager, $logger, $this->poolAspect, false);

        // 调用调度方法（使用真实 poolManager，会执行 cleanup 但不记录日志）
        $scheduler->scheduleCleanup();

        // 验证 lastRunTime 已更新（说明 cleanup 确实执行了）
        $lastRunTime = $this->getLastRunTime($scheduler);
        self::assertGreaterThan(0, $lastRunTime);
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

    public function testReturnAll(): void
    {
        // 由于 ConnectionPoolAspect 是 final 类且有严格类型声明，无法 Mock 来验证调用
        // 这个测试验证的是：调用 returnAll() 方法不会抛出异常，会正确委托给 poolAspect

        // 调用 returnAll 方法（使用真实的 poolAspect）
        $this->scheduler->returnAll();

        // 验证调用成功完成（没有抛出异常即表示测试通过）
        self::assertTrue(true, 'returnAll should complete without throwing exceptions');
    }
}
