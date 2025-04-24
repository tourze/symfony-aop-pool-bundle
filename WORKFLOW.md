# AopPoolBundle 工作流程（Mermaid）

```mermaid
flowchart TD
    A[请求到达] --> B{需要连接服务?}
    B -- 否 --> Z[正常处理]
    B -- 是 --> C[查找服务是否已池化]
    C -- 已池化 --> D[从连接池借出连接]
    C -- 未池化 --> E[创建新连接并池化]
    D --> F[执行业务逻辑]
    E --> F
    F --> G[连接归还池]
    G --> H[健康检查/资源回收]
    H --> Z
```

## 说明

- 所有通过 `#[ConnectionPool]` 注解、`snc_redis.client` 标签、`doctrine.dbal.*_connection` 服务都会自动池化。
- 连接借出和归还由 AOP 拦截自动完成。
- 池管理器与生命周期处理器共同确保资源高效复用与健康。
- 支持定时清理与健康检查，防止连接泄漏。
