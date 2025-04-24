# AopPoolBundle

[English](README.md) | [中文](README.zh-CN.md)

[![Latest Version](https://img.shields.io/packagist/v/tourze/symfony-aop-pool-bundle.svg?style=flat-square)](https://packagist.org/packages/tourze/symfony-aop-pool-bundle)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

AopPoolBundle is a Symfony bundle for automatic connection pooling using AOP (Aspect-Oriented Programming). It provides efficient resource pooling for Redis, database, and custom services, improving performance and resource utilization.

## Features

- Automatic lifecycle management for connections (borrow/return)
- Built-in Redis and Doctrine DBAL connection pools
- Custom poolable services via `#[ConnectionPool]` attribute
- Connection health checks and resource recycling
- Lazy initialization, auto-reconnect, and retry support
- Detailed logging and debug support
- Compatible with FPM and Workerman environments

## Installation

- Requires PHP 8.1+, Symfony 6.4+
- Dependencies: `doctrine/dbal`, `snc/redis-bundle`, etc.

```bash
composer require tourze/symfony-aop-pool-bundle
```

## Quick Start

### 1. Mark Custom Service for Pooling

```php
use Tourze\Symfony\AopPoolBundle\Attribute\ConnectionPool;

#[ConnectionPool]
class YourService {
    private $connection;
    public function doSomething() {
        // Connection is automatically fetched from the pool
        $result = $this->connection->query(...);
        return $result;
    }
}
```

### 2. Redis Connection Pool

Redis clients are automatically pooled, no extra configuration required:

```php
class YourService {
    public function __construct(private \Redis $redis) {}
    public function doSomething() {
        return $this->redis->get('key');
    }
}
```

### 3. Database Connection Pool

Doctrine DBAL connections are automatically pooled:

```php
use Doctrine\DBAL\Connection;
class YourService {
    public function __construct(private Connection $connection) {}
    public function doSomething() {
        return $this->connection->executeQuery('SELECT ...');
    }
}
```

## Services Automatically Pooled

- All services tagged as `snc_redis.client`
- All `doctrine.dbal.*_connection` services
- All services with the `#[ConnectionPool]` attribute

## Configuration

Add the following to your `.env` if you need to customize:

```dotenv
SERVICE_POOL_DEFAULT_SIZE=500
SERVICE_POOL_CLEANUP_INTERVAL=60
SERVICE_POOL_CONNECTION_LIFETIME=60
SERVICE_POOL_CHECK_REDIS_CONNECTION=0
DEBUG_ConnectionPoolAspect=true
```

## Performance & Debugging Tips

- Pool only necessary services, set pool size appropriately
- Enable debug logs to monitor pool usage
- Ensure connections are properly released

## Notes & Limitations

- Connections are automatically returned to the pool at the end of each request
- Pool health checks regularly recycle a small portion of connections to prevent resource exhaustion
- Default pool size: 500 (configurable)
- Max retry attempts = pool size + 1
- Pool switching in transactions is not supported
- Some special services may not be suitable for pooling
- StopWorkerException is thrown if connection fetch fails; automatic retry supported
- Reconnect attempts and intervals are configurable
- Works well in both short-lived (FPM) and long-lived (Workerman) process environments

## Contribution

- Please use Issues for bug reports and feature requests
- Pull Requests are welcome; ensure tests and code style pass
- Follow PSR standards

## License

MIT License © Tourze

## Changelog

See [CHANGELOG.md] if available.
