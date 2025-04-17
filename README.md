# AopPoolBundle

AopPoolBundle 是一个基于 Symfony 的连接池实现，通过 AOP 技术实现对数据库连接、Redis 连接等资源的池化管理，主要用于提高资源利用率和系统性能。

## 核心功能

### 1. 连接池管理
- 自动管理连接的生命周期
- 支持连接的借出和归还
- 智能的连接复用策略
- 自动处理连接失效和重连

### 2. 内置连接池支持
- Redis 连接池（支持 SncRedisBundle）
- 数据库连接池（支持 Doctrine DBAL）
- 支持通过注解添加自定义连接池

### 3. 连接监控
- 连接使用状态追踪
- 连接老化检测
- 自动销毁过期连接
- 详细的日志记录

### 4. 性能优化
- 延迟连接初始化
- 智能连接复用
- 自动资源回收
- 连接重试机制

## 配置参数

在 `.env` 文件中可以配置以下参数：

```dotenv
# 连接池默认大小
SERVICE_POOL_DEFAULT_SIZE=500

# 调试模式（会记录详细日志）
DEBUG_ConnectionPoolAspect=true
```

## 使用示例

### 1. 标记连接池服务

```php
use AopPoolBundle\Attribute\ConnectionPool;

#[ConnectionPool]
class YourService
{
    private $connection;

    public function doSomething()
    {
        // 连接会自动从连接池获取
        $result = $this->connection->query(...);
        return $result;
    }
}
```

### 2. 使用 Redis 连接池

Redis 客户端会自动使用连接池，无需额外配置：

```php
class YourService
{
    public function __construct(
        private \Redis $redis
    ) {}
    
    public function doSomething()
    {
        // 连接会自动从连接池获取
        return $this->redis->get('key');
    }
}
```

### 3. 使用数据库连接池

Doctrine DBAL 连接会自动使用连接池，无需额外配置：

```php
use Doctrine\DBAL\Connection;

class YourService
{
    public function __construct(
        private Connection $connection
    ) {}
    
    public function doSomething()
    {
        // 连接会自动从连接池获取
        return $this->connection->executeQuery('SELECT ...');
    }
}
```

## 自动池化的服务

以下服务会被自动池化：

1. Redis 相关：
   - 所有标记为 `snc_redis.client` 的服务

2. 数据库相关：
   - 所有 `doctrine.dbal.*_connection` 服务

3. 自定义服务：
   - 所有使用 `#[ConnectionPool]` 注解标记的服务

## 注意事项

1. 连接管理
   - 连接会在每个请求结束时自动归还到连接池
   - 过期的连接会被自动销毁而不是归还
   - 连接默认存活时间为 1 分钟
   - 连接池大小默认为 500

2. 性能考虑
   - 只对需要池化的服务使用连接池
   - 合理设置连接池大小
   - 注意监控连接池使用情况

3. 调试建议
   - 开启 DEBUG_ConnectionPoolAspect 获取详细日志
   - 监控连接池大小和使用率
   - 注意检查连接是否正确释放

4. 限制
   - 连接重试最大次数等于连接池大小加1
   - 不支持事务中的连接池切换
   - 某些特殊服务可能不适合池化

5. 错误处理
   - 连接获取失败会抛出 StopWorkerException
   - 连接过期会自动重试获取新连接
   - 支持配置重连次数和重试间隔
