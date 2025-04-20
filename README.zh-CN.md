# AopPoolBundle

AopPoolBundle 是一个基于 Symfony 的 AOP 连接池扩展，旨在为 Redis、数据库等资源提供高效的池化管理。通过 AOP 技术自动拦截并池化服务，提升系统性能和资源利用率。

![version](https://img.shields.io/badge/version-0.0.1-blue.svg) ![license](https://img.shields.io/badge/license-MIT-green.svg)

## 主要特性

- 自动管理连接生命周期，支持借出与归还
- 支持 Redis、Doctrine DBAL 数据库连接池
- 支持通过注解 `#[ConnectionPool]` 自定义池化服务
- 连接池状态监控、老化检测与自动销毁
- 延迟初始化、自动重连、重试与资源回收
- 详细日志与调试支持

## 安装方法

- 依赖 PHP 8.1 及以上，Symfony 6.4 及以上
- 需安装 `doctrine/dbal`、`snc/redis-bundle` 等依赖

```bash
composer require tourze/symfony-aop-pool-bundle
```

## 快速开始

### 1. 标记自定义服务池化

```php
use Tourze\Symfony\AopPoolBundle\Attribute\ConnectionPool;

#[ConnectionPool]
class YourService {
    private $connection;
    public function doSomething() {
        // 连接将自动从连接池获取
        $result = $this->connection->query(...);
        return $result;
    }
}
```

### 2. Redis 连接池

Redis 客户端会自动池化，无需额外配置：

```php
class YourService {
    public function __construct(private \Redis $redis) {}
    public function doSomething() {
        return $this->redis->get('key');
    }
}
```

### 3. 数据库连接池

Doctrine DBAL 连接会自动池化：

```php
use Doctrine\DBAL\Connection;
class YourService {
    public function __construct(private Connection $connection) {}
    public function doSomething() {
        return $this->connection->executeQuery('SELECT ...');
    }
}
```

## 自动池化的服务

- 所有 `snc_redis.client` 服务
- 所有 `doctrine.dbal.*_connection` 服务
- 所有带 `#[ConnectionPool]` 注解的服务

## 配置说明

`.env` 文件支持如下配置：

```dotenv
SERVICE_POOL_DEFAULT_SIZE=500
DEBUG_ConnectionPoolAspect=true
```

## 注意事项

- 连接会在每次请求结束后自动归还
- 过期连接会被销毁，默认存活 1 分钟
- 池大小默认为 500，可配置
- 连接重试次数为池大小+1
- 不支持事务中切换池
- 某些特殊服务不建议池化
- 获取失败抛出 StopWorkerException，自动重试
- 支持重连次数与间隔配置

## 性能与调试建议

- 仅池化必要服务，合理设置池大小
- 开启调试日志监控池使用率
- 检查连接是否正确释放

## 贡献指南

1. 通过 Issue 反馈问题
2. 提交 PR 前请确保通过测试与代码规范
3. 遵循 PSR 标准

## 版权许可

MIT License © Tourze

## 更新日志

详见 [CHANGELOG.md]（如有）
