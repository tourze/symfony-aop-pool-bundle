<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect;
use Tourze\Symfony\AopPoolBundle\DependencyInjection\AopPoolExtension;
use Tourze\Symfony\AopPoolBundle\EventSubscriber\PoolCleanupScheduler;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionLifecycleHandler;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager;

class AopPoolExtensionTest extends TestCase
{
    private AopPoolExtension $extension;
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension = new AopPoolExtension();
        $this->container = new ContainerBuilder();
    }

    public function testServiceDefinitions(): void
    {
        // 加载扩展
        $this->extension->load([], $this->container);

        // 检查是否注册了关键服务
        $this->assertTrue($this->container->hasDefinition(ConnectionPoolAspect::class));
        $this->assertTrue($this->container->hasDefinition(ConnectionPoolManager::class));
        $this->assertTrue($this->container->hasDefinition(ConnectionLifecycleHandler::class));
        $this->assertTrue($this->container->hasDefinition(PoolCleanupScheduler::class));

        // 检查日志服务
        $this->assertTrue($this->container->hasDefinition('monolog.logger.connection_pool'));

        // 断言monolog.logger.connection_pool有正确的channel参数
        $loggerDefinition = $this->container->getDefinition('monolog.logger.connection_pool');
        $this->assertEquals('connection_pool', $loggerDefinition->getArgument('$channel'));

        // 检查是否有useLoggingLoopDetection调用
        $methodCalls = $loggerDefinition->getMethodCalls();
        $hasLoopDetectionCall = false;
        foreach ($methodCalls as $call) {
            if ($call[0] === 'useLoggingLoopDetection') {
                $hasLoopDetectionCall = true;
                $this->assertEquals(false, $call[1][0]);
                break;
            }
        }
        $this->assertTrue($hasLoopDetectionCall, 'Missing useLoggingLoopDetection method call');
    }
}
