<?php

namespace mix\client;

/**
 * redis长连接组件
 * @author 刘健 <coder.liu@qq.com>
 */
class RedisPersistent extends BaseRedisPersistent
{

    public function onRequestBefore()
    {
        parent::onRequestBefore();
        // 切换回默认数据库
        if (isset($this->_redis)) {
            $this->select($this->database);
        }
    }

    // 析构事件
    public function onDestruct()
    {
        parent::onDestruct();
        // 关闭连接
        $this->disconnect();
    }

}
