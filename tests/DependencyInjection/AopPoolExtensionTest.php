<?php

namespace Tourze\Symfony\AopPoolBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tourze\PHPUnitSymfonyUnitTest\AbstractDependencyInjectionExtensionTestCase;
use Tourze\Symfony\AopPoolBundle\Aspect\ConnectionPoolAspect;
use Tourze\Symfony\AopPoolBundle\DependencyInjection\AopPoolExtension;
use Tourze\Symfony\AopPoolBundle\EventSubscriber\PoolCleanupScheduler;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionLifecycleHandler;
use Tourze\Symfony\AopPoolBundle\Service\ConnectionPoolManager;

/**
 * @internal
 */
#[CoversClass(AopPoolExtension::class)]
final class AopPoolExtensionTest extends AbstractDependencyInjectionExtensionTestCase
{
    private AopPoolExtension $extension;

    private ContainerBuilder $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension = new AopPoolExtension();
        $this->container = new ContainerBuilder();
        $this->container->setParameter('kernel.environment', 'test');
    }

    public function testServiceDefinitions(): void
    {
        // 加载扩展
        $this->extension->load([], $this->container);

        // 检查是否注册了关键服务
        self::assertTrue($this->container->hasDefinition(ConnectionPoolAspect::class));
        self::assertTrue($this->container->hasDefinition(ConnectionPoolManager::class));
        self::assertTrue($this->container->hasDefinition(ConnectionLifecycleHandler::class));
        self::assertTrue($this->container->hasDefinition(PoolCleanupScheduler::class));
    }

    public function testServiceConfiguration(): void
    {
        // 加载扩展
        $this->extension->load([], $this->container);

        // 检查Aspect服务配置
        $aspectDefinition = $this->container->getDefinition(ConnectionPoolAspect::class);
        self::assertTrue($aspectDefinition->isAutowired());
        self::assertTrue($aspectDefinition->isAutoconfigured());

        // 检查ConnectionPoolManager服务配置
        $poolManagerDefinition = $this->container->getDefinition(ConnectionPoolManager::class);
        self::assertTrue($poolManagerDefinition->isAutowired());
        self::assertTrue($poolManagerDefinition->isAutoconfigured());

        // 检查ConnectionLifecycleHandler服务配置
        $lifecycleHandlerDefinition = $this->container->getDefinition(ConnectionLifecycleHandler::class);
        self::assertTrue($lifecycleHandlerDefinition->isAutowired());
        self::assertTrue($lifecycleHandlerDefinition->isAutoconfigured());

        // 检查PoolCleanupScheduler服务配置
        $schedulerDefinition = $this->container->getDefinition(PoolCleanupScheduler::class);
        self::assertTrue($schedulerDefinition->isAutowired());
        self::assertTrue($schedulerDefinition->isAutoconfigured());
    }

    public function testYamlLoader(): void
    {
        // 检查使用的FileLocator路径是否正确
        $reflectionClass = new \ReflectionClass(AopPoolExtension::class);
        $fileName = $reflectionClass->getFileName();
        self::assertIsString($fileName, 'Class file should exist');
        $expectedPath = dirname($fileName) . '/../Resources/config';
        self::assertDirectoryExists($expectedPath);
        self::assertFileExists($expectedPath . '/services.yaml');
    }

    /**
     * 测试 load() 方法
     */
    public function testLoad(): void
    {
        // 准备配置数组
        $configs = [];

        // 调用 load 方法
        $this->extension->load($configs, $this->container);

        // 验证容器已加载服务
        self::assertTrue($this->container->hasDefinition(ConnectionPoolAspect::class));
        self::assertTrue($this->container->hasDefinition(ConnectionPoolManager::class));
        self::assertTrue($this->container->hasDefinition(ConnectionLifecycleHandler::class));
        self::assertTrue($this->container->hasDefinition(PoolCleanupScheduler::class));

        // 验证 InstanceService 也被加载
        self::assertTrue($this->container->hasDefinition('Tourze\Symfony\Aop\Service\InstanceService'));
    }
}
