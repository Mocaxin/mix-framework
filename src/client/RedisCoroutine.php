<?php

namespace mix\client;

use mix\helpers\CoroutineHelper;

/**
 * Redis组件
 * @author 刘健 <coder.liu@qq.com>
 */
class RedisCoroutine extends BaseRedisPersistent
{

    /**
     * 连接池
     * @var \mix\pool\ConnectionPool
     */
    public $connectionPool;

    // 初始化事件
    public function onInitialize()
    {
        parent::onInitialize(); // TODO: Change the autogenerated stub
        // 开启协程
        CoroutineHelper::enableCoroutine();
    }

    // 析构事件
    public function onDestruct()
    {
        parent::onDestruct();
        // 关闭连接
        $this->disconnect();
    }

    // 创建连接
    protected function createConnection()
    {
        $this->connectionPool->activeCountIncrement();
        $redis = parent::createConnection();
        return $redis;
    }

    // 获取连接
    protected function getConnection()
    {
        if ($this->connectionPool->getQueueCount() > 0) {
            return $this->connectionPool->pop();
        }
        if ($this->connectionPool->getCurrentCount() >= $this->connectionPool->max) {
            return $this->connectionPool->pop();
        }
        return $this->createConnection();
    }

    // 连接
    protected function connect()
    {
        $this->_redis = $this->getConnection();
    }

    // 关闭连接
    public function disconnect()
    {
        if (isset($this->_redis)) {
            $this->connectionPool->push($this->_redis);
            $this->connectionPool->activeCountDecrement();
        }
        parent::disconnect();
    }

    // 重新连接
    protected function reconnect()
    {
        parent::disconnect();
        $this->connectionPool->activeCountDecrement();
        $this->connect();
    }

}
