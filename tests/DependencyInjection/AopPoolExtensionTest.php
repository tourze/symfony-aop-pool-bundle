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
    }
    
    public function testServiceConfiguration(): void
    {
        // 加载扩展
        $this->extension->load([], $this->container);
        
        // 检查Aspect服务配置
        $aspectDefinition = $this->container->getDefinition(ConnectionPoolAspect::class);
        $this->assertTrue($aspectDefinition->isAutowired());
        $this->assertTrue($aspectDefinition->isAutoconfigured());
        
        // 检查ConnectionPoolManager服务配置
        $poolManagerDefinition = $this->container->getDefinition(ConnectionPoolManager::class);
        $this->assertTrue($poolManagerDefinition->isAutowired());
        $this->assertTrue($poolManagerDefinition->isAutoconfigured());
        
        // 检查ConnectionLifecycleHandler服务配置
        $lifecycleHandlerDefinition = $this->container->getDefinition(ConnectionLifecycleHandler::class);
        $this->assertTrue($lifecycleHandlerDefinition->isAutowired());
        $this->assertTrue($lifecycleHandlerDefinition->isAutoconfigured());
        
        // 检查PoolCleanupScheduler服务配置
        $schedulerDefinition = $this->container->getDefinition(PoolCleanupScheduler::class);
        $this->assertTrue($schedulerDefinition->isAutowired());
        $this->assertTrue($schedulerDefinition->isAutoconfigured());
    }
    
    public function testYamlLoader(): void
    {
        // 加载扩展
        $this->extension->load([], $this->container);
        
        // 检查使用的FileLocator路径是否正确
        $reflectionClass = new \ReflectionClass(AopPoolExtension::class);
        $expectedPath = dirname($reflectionClass->getFileName()) . '/../Resources/config';
        $this->assertDirectoryExists($expectedPath);
        $this->assertFileExists($expectedPath . '/services.yaml');
    }
}
