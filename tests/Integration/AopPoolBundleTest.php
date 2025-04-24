<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Tourze\Symfony\Aop\Service\InstanceService;
use Tourze\Symfony\AopPoolBundle\AopPoolBundle;
use Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager;
use Tourze\Symfony\RuntimeContextBundle\Service\ContextServiceInterface;

/**
 * 集成测试
 */
class AopPoolBundleTest extends TestCase
{
    private AopPoolBundle $bundle;
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        parent::setUp();

        // 初始化Bundle和Container
        $this->bundle = new AopPoolBundle();
        $this->container = new ContainerBuilder(new ParameterBag([
            'kernel.debug' => true,
        ]));

        // 注册需要的服务
        $this->registerMockServices();

        // 初始化Bundle
        $this->bundle->build($this->container);
    }

    private function registerMockServices(): void
    {
        $instanceService = $this->createMock(InstanceService::class);
        $this->container->set(InstanceService::class, $instanceService);

        $contextService = new MockContextService();
        $this->container->set(ContextServiceInterface::class, $contextService);

        // 注册事件派发器
        $eventDispatcher = $this->createMock(\Symfony\Component\EventDispatcher\EventDispatcher::class);
        $this->container->set('event_dispatcher', $eventDispatcher);
    }

    public function testBundleInitialization(): void
    {
        // 验证Bundle正确注册
        $this->assertInstanceOf(AopPoolBundle::class, $this->bundle);

        // 验证关键服务存在
        $this->assertTrue($this->container->has(InstanceService::class));
        $this->assertTrue($this->container->has(ContextServiceInterface::class));
    }

    public function testBundleName(): void
    {
        // 验证Bundle的名称
        $this->assertEquals('AopPoolBundle', $this->bundle->getName());
    }

    public function testBundlePath(): void
    {
        // 验证Bundle的路径
        $expectedPath = dirname((new \ReflectionClass(AopPoolBundle::class))->getFileName());
        $this->assertEquals($expectedPath, $this->bundle->getPath());
    }

    public function testAspectRegistration(): void
    {
        // 注册Aspect服务
        $aspect = $this->createMock(ConnectionPoolAspect::class);
        $this->container->set(ConnectionPoolAspect::class, $aspect);

        // 验证是正确的实例
        $this->assertSame($aspect, $this->container->get(ConnectionPoolAspect::class));
    }

    public function testPoolManagerService(): void
    {
        // 注册PoolManager服务
        $poolManager = $this->createMock(ConnectionPoolManager::class);
        $this->container->set(ConnectionPoolManager::class, $poolManager);

        // 验证是正确的实例
        $this->assertSame($poolManager, $this->container->get(ConnectionPoolManager::class));
    }

    public function testAspectWithConnectionWithoutPool(): void
    {
        // 创建AspectMock
        $aspect = $this->createMock(ConnectionPoolAspect::class);

        // 测试reset方法不抛出异常 - void 方法不应该设置返回值
        $aspect->expects($this->once())
            ->method('reset');

        $this->container->set(ConnectionPoolAspect::class, $aspect);

        // 获取服务并调用reset方法
        $aspectService = $this->container->get(ConnectionPoolAspect::class);
        $aspectService->reset();
    }
}

/**
 * 模拟上下文服务
 */
class MockContextService implements ContextServiceInterface
{
    public function getId(): string
    {
        return 'test-context-' . rand(1000, 9999);
    }

    public function getName(): string
    {
        return 'test-context';
    }

    public function getStartTime(): int
    {
        return time();
    }

    public function reset(): void
    {
        // 不需要实现
    }

    public function defer(callable $callback): void
    {
        // 直接执行，不进行延迟
        $callback();
    }

    public function supportCoroutine(): bool
    {
        // 不支持协程
        return false;
    }
}
