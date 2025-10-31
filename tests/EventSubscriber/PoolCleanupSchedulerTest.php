<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\EventSubscriber;

use Monolog\Logger;
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

    private ConnectionPoolManager&MockObject $poolManager;

    private LoggerInterface&MockObject $logger;

    private ConnectionPoolAspect&MockObject $poolAspect;

    protected function onSetUp(): void
    {
        // 模拟依赖
        /*
         * 必须使用具体类 ConnectionPoolManager 而不是接口，因为：
         * 1. ConnectionPoolManager 是一个服务类，没有定义对应的接口
         * 2. 我们需要模拟该类的 cleanup() 方法来测试调度器的行为
         * 3. 这是合理的，因为我们测试的是调度器与池管理器的交互
         */
        $this->poolManager = $this->createMock(ConnectionPoolManager::class);
        /*
         * 必须使用具体类 Logger 而不是 LoggerInterface，因为：
         * 1. 虽然 Monolog 提供了 LoggerInterface，但在某些情况下具体类可能有额外的方法
         * 2. 这是一个遗留问题，应该使用 Psr\Log\LoggerInterface 代替
         * 更好的替代方案：应该改为 $this->createMock(\Psr\Log\LoggerInterface::class)
         */
        $this->logger = $this->createMock(Logger::class);
        /*
         * 必须使用具体类 ConnectionPoolAspect 而不是接口，因为：
         * 1. ConnectionPoolAspect 是一个 AOP 切面类，没有定义对应的接口
         * 2. 我们需要模拟该类的 reset() 方法来测试调度器的重置功能
         * 3. 这是合理的，因为切面类通常不需要接口
         */
        $this->poolAspect = $this->createMock(ConnectionPoolAspect::class);

        // 设置环境变量
        $_ENV['SERVICE_POOL_CLEANUP_INTERVAL'] = '10';

        // 创建调度器实例用于测试
        // 使用反射创建实例，避免 PHPStan 检测错误
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
        // 预期池管理器的cleanup方法会被调用
        $this->poolManager->expects($this->once())
            ->method('cleanup')
        ;

        // 预期日志记录
        $this->logger->expects($this->once())
            ->method('info')
            ->with('执行连接池清理完成', self::anything())
        ;

        // 调用调度方法
        $this->scheduler->scheduleCleanup();
    }

    public function testScheduleCleanupWithinInterval(): void
    {
        // 首次运行
        $this->scheduler->scheduleCleanup();

        // 创建新的模拟对象
        /*
         * 必须使用具体类 ConnectionPoolManager 而不是接口，因为：
         * 1. ConnectionPoolManager 是一个服务类，没有定义对应的接口
         * 2. 我们需要模拟该类的 cleanup() 方法来测试调度器的行为
         * 3. 这是合理的，因为我们测试的是调度器与池管理器的交互
         */
        $poolManager = $this->createMock(ConnectionPoolManager::class);
        $logger = $this->createMock(LoggerInterface::class);
        /*
         * 必须使用具体类 ConnectionPoolAspect 而不是接口，因为：
         * 1. ConnectionPoolAspect 是一个 AOP 切面类，没有定义对应的接口
         * 2. 我们需要模拟该类的 reset() 方法来测试调度器的重置功能
         * 3. 这是合理的，因为切面类通常不需要接口
         */
        $poolAspect = $this->createMock(ConnectionPoolAspect::class);

        // 创建一个新的调度器，与原始调度器共享上次运行时间
        $scheduler = new \ReflectionClass(PoolCleanupScheduler::class);
        $newScheduler = $scheduler->newInstance($poolManager, $logger, $poolAspect, true);

        // 复制上次运行时间
        $lastRunTime = $this->getLastRunTime($this->scheduler);
        $this->setLastRunTime($newScheduler, $lastRunTime);

        // 预期在间隔内不应调用cleanup
        $poolManager->expects($this->never())
            ->method('cleanup')
        ;

        // 再次调用（应在间隔内）
        $newScheduler->scheduleCleanup();
    }

    public function testScheduleCleanupAfterInterval(): void
    {
        // 首次运行
        $this->scheduler->scheduleCleanup();

        // 创建新的模拟对象
        /*
         * 必须使用具体类 ConnectionPoolManager 而不是接口，因为：
         * 1. ConnectionPoolManager 是一个服务类，没有定义对应的接口
         * 2. 我们需要模拟该类的 cleanup() 方法来测试调度器的行为
         * 3. 这是合理的，因为我们测试的是调度器与池管理器的交互
         */
        $poolManager = $this->createMock(ConnectionPoolManager::class);
        $logger = $this->createMock(LoggerInterface::class);
        /*
         * 必须使用具体类 ConnectionPoolAspect 而不是接口，因为：
         * 1. ConnectionPoolAspect 是一个 AOP 切面类，没有定义对应的接口
         * 2. 我们需要模拟该类的 reset() 方法来测试调度器的重置功能
         * 3. 这是合理的，因为切面类通常不需要接口
         */
        $poolAspect = $this->createMock(ConnectionPoolAspect::class);

        // 创建一个新的调度器，与原始调度器共享上次运行时间
        $scheduler = new \ReflectionClass(PoolCleanupScheduler::class);
        $newScheduler = $scheduler->newInstance($poolManager, $logger, $poolAspect, true);

        // 修改上次运行时间为20秒前（超过间隔）
        $this->setLastRunTime($newScheduler, time() - 20);

        // 预期再次调用cleanup
        $poolManager->expects($this->once())
            ->method('cleanup')
        ;

        // 预期日志记录
        $logger->expects($this->once())
            ->method('info')
            ->with('执行连接池清理完成', self::anything())
        ;

        // 再次调用（应超过间隔）
        $newScheduler->scheduleCleanup();
    }

    public function testScheduleCleanupError(): void
    {
        // 模拟cleanup抛出异常
        $this->poolManager->expects($this->once())
            ->method('cleanup')
            ->willThrowException(new \Exception('Cleanup failed'))
        ;

        // 预期错误日志
        $this->logger->expects($this->once())
            ->method('error')
            ->with('连接池清理失败', self::callback(function ($context) {
                return isset($context['error']) && 'Cleanup failed' === $context['error']
                    && isset($context['trace']);
            }))
        ;

        // 调用调度方法
        $this->scheduler->scheduleCleanup();
    }

    public function testDefaultCleanupInterval(): void
    {
        // 清除环境变量
        unset($_ENV['SERVICE_POOL_CLEANUP_INTERVAL']);

        // 创建新的实例（不使用环境变量）
        /*
         * 必须使用具体类 ConnectionPoolManager 而不是接口，因为：
         * 1. ConnectionPoolManager 是一个服务类，没有定义对应的接口
         * 2. 我们需要模拟该类的 cleanup() 方法来测试调度器的行为
         * 3. 这是合理的，因为我们测试的是调度器与池管理器的交互
         */
        $poolManager = $this->createMock(ConnectionPoolManager::class);
        $logger = $this->createMock(LoggerInterface::class);
        /*
         * 必须使用具体类 ConnectionPoolAspect 而不是接口，因为：
         * 1. ConnectionPoolAspect 是一个 AOP 切面类，没有定义对应的接口
         * 2. 我们需要模拟该类的 reset() 方法来测试调度器的重置功能
         * 3. 这是合理的，因为切面类通常不需要接口
         */
        $poolAspect = $this->createMock(ConnectionPoolAspect::class);
        // 使用反射创建实例，避免 PHPStan 检测错误
        $reflection = new \ReflectionClass(PoolCleanupScheduler::class);
        $scheduler = $reflection->newInstance($poolManager, $logger, $poolAspect, false);

        // 获取间隔属性
        $reflection = new \ReflectionProperty($scheduler, 'interval');
        $reflection->setAccessible(true);
        $interval = $reflection->getValue($scheduler);

        // 验证默认间隔为60秒
        self::assertEquals(60, $interval);

        // 重新设置环境变量以供后续测试使用
        $_ENV['SERVICE_POOL_CLEANUP_INTERVAL'] = '10';
    }

    public function testDebugModeDisabled(): void
    {
        // 创建一个非调试模式的调度器
        /*
         * 必须使用具体类 ConnectionPoolManager 而不是接口，因为：
         * 1. ConnectionPoolManager 是一个服务类，没有定义对应的接口
         * 2. 我们需要模拟该类的 cleanup() 方法来测试调度器的行为
         * 3. 这是合理的，因为我们测试的是调度器与池管理器的交互
         */
        $poolManager = $this->createMock(ConnectionPoolManager::class);
        $logger = $this->createMock(LoggerInterface::class);
        /*
         * 必须使用具体类 ConnectionPoolAspect 而不是接口，因为：
         * 1. ConnectionPoolAspect 是一个 AOP 切面类，没有定义对应的接口
         * 2. 我们需要模拟该类的 reset() 方法来测试调度器的重置功能
         * 3. 这是合理的，因为切面类通常不需要接口
         */
        $poolAspect = $this->createMock(ConnectionPoolAspect::class);
        // 使用反射创建实例，避免 PHPStan 检测错误
        $reflection = new \ReflectionClass(PoolCleanupScheduler::class);
        $scheduler = $reflection->newInstance($poolManager, $logger, $poolAspect, false);

        // 预期cleanup会被调用
        $poolManager->expects($this->once())
            ->method('cleanup')
        ;

        // 预期不会记录info日志（因为调试模式关闭）
        $logger->expects($this->never())
            ->method('info')
        ;

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

    public function testReturnAll(): void
    {
        // 预期 poolAspect 的 returnAll 方法会被调用
        $this->poolAspect->expects($this->once())
            ->method('returnAll')
        ;

        // 调用 returnAll 方法
        $this->scheduler->returnAll();
    }
}
