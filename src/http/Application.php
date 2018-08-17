<?php

namespace mix\http;

use mix\base\Component;
use mix\helpers\PhpInfoHelper;

/**
 * App类
 * @author 刘健 <coder.liu@qq.com>
 */
class Application extends \mix\base\Application
{

    // 控制器命名空间
    public $controllerNamespace = '';

    // 中间件命名空间
    public $middlewareNamespace = '';

    // 全局中间件
    public $middleware = [];

    // 协程组件容器
    protected $_coroutineComponents = [];

    // 是否为协程环境
    protected $_isCoroutine;

    // 初始化事件
    public function onInitialize()
    {
        parent::onInitialize(); // TODO: Change the autogenerated stub
        // 是否为协程环境
        $this->_isCoroutine = PhpInfoHelper::isCoroutine();
    }

    // 执行功能
    public function run()
    {
        $server                        = \Mix::app()->request->server();
        $method                        = strtoupper($server['request_method']);
        $action                        = empty($server['path_info']) ? '' : substr($server['path_info'], 1);
        \Mix::app()->response->content = $this->runAction($method, $action);
        \Mix::app()->response->send();
    }

    // 执行功能并返回
    public function runAction($method, $action)
    {
        $action = "{$method} {$action}";
        // 路由匹配
        $result = \Mix::app()->route->match($action);
        foreach ($result as $item) {
            list($route, $queryParams) = $item;
            // 路由参数导入请求类
            \Mix::app()->request->setRoute($queryParams);
            // 实例化控制器
            list($shortClass, $shortAction) = $route;
            $controllerDir    = \mix\helpers\FileSystemHelper::dirname($shortClass);
            $controllerDir    = $controllerDir == '.' ? '' : "$controllerDir\\";
            $controllerName   = \mix\helpers\NameHelper::snakeToCamel(\mix\helpers\FileSystemHelper::basename($shortClass), true);
            $controllerClass  = "{$this->controllerNamespace}\\{$controllerDir}{$controllerName}Controller";
            $shortAction      = \mix\helpers\NameHelper::snakeToCamel($shortAction, true);
            $controllerAction = "action{$shortAction}";
            // 判断类是否存在
            if (class_exists($controllerClass)) {
                $controllerInstance = new $controllerClass();
                // 判断方法是否存在
                if (method_exists($controllerInstance, $controllerAction)) {
                    // 执行中间件
                    $middleware = $this->newMiddlewareInstance($route['middleware']);
                    if (!empty($middleware)) {
                        return $this->runMiddleware([$controllerInstance, $controllerAction], $middleware);
                    }
                    // 直接返回执行结果
                    return $controllerInstance->$controllerAction();
                }
            }
            // 不带路由参数的路由规则找不到时，直接抛出错误
            if (empty($queryParams)) {
                break;
            }
        }
        throw new \mix\exceptions\NotFoundException('Not Found (#404)');
    }

    // 执行中间件
    protected function runMiddleware($callable, $middleware)
    {
        $item = array_shift($middleware);
        if (empty($item)) {
            return call_user_func($callable);
        }
        return $item->handle($callable, function () use ($callable, $middleware) {
            return $this->runMiddleware($callable, $middleware);
        });
    }

    // 实例化中间件
    protected function newMiddlewareInstance($routeMiddleware)
    {
        $middleware = [];
        foreach (array_merge($this->middleware, $routeMiddleware) as $key => $name) {
            $class            = "{$this->middlewareNamespace}\\{$name}Middleware";
            $middleware[$key] = new $class();
        }
        return $middleware;
    }

    // 获取组件
    public function __get($name)
    {
        // 获取全名
        if (!is_null($this->_componentPrefix)) {
            $name = "{$this->_componentPrefix}.{$name}";
        }
        $this->setComponentPrefix(null);
        /* 协程模式 */
        // 返回协程组件单例
        if ($this->_isCoroutine) {
            $coroutineId = \Swoole\Coroutine::getuid();
            // 创建协程组件
            if (!isset($this->_coroutineComponents[$coroutineId][$name])) {
                // 关联组件加载
                if (!isset($this->_components[$name])) {
                    $this->loadComponent($name);
                }
                // 创建协程组件
                if ($this->_components[$name]->getCoroutineMode() == Component::COROUTINE_MODE_NEW) {
                    $this->_coroutineComponents[$coroutineId][$name] = $this->loadComponent($name, true);
                    // 触发请求开始事件
                    $this->triggerRequestStart($this->_coroutineComponents[$coroutineId][$name]);
                }
                // 引用协程组件
                if ($this->_components[$name]->getCoroutineMode() == Component::COROUTINE_MODE_REFERENCE) {
                    $this->_coroutineComponents[$coroutineId][$name] = $this->_components[$name];
                }
            }
            // 返回对象
            return $this->_coroutineComponents[$coroutineId][$name];
        }
        /* 常驻模式 */
        // 返回单例
        if (isset($this->_components[$name])) {
            // 触发请求开始事件
            $this->triggerRequestStart($this->_components[$name]);
            // 返回对象
            return $this->_components[$name];
        }
        /* 传统模式 */
        // 装载组件
        $this->loadComponent($name);
        // 触发请求开始事件
        $this->triggerRequestStart($this->_components[$name]);
        // 返回单例
        return $this->_components[$name];
    }

    // 装载全部组件
    public function loadAllComponents()
    {
        foreach (array_keys($this->components) as $name) {
            $this->loadComponent($name);
        }
    }

    // 清扫组件容器
    public function cleanComponents()
    {
        // 删除协程组件
        if ($this->_isCoroutine) {
            $coroutineId = \Swoole\Coroutine::getuid();
            if (isset($this->_coroutineComponents[$coroutineId])) {
                // 触发请求结束事件
                foreach ($this->_coroutineComponents[$coroutineId] as $component) {
                    $this->triggerRequestEnd($component);
                }
            }
            // 删除协程组件
            $this->_coroutineComponents[$coroutineId] = null;
            return;
        }
        // 触发请求结束事件
        foreach ($this->_components as $component) {
            $this->triggerRequestEnd($component);
        }
    }

    // 触发请求开始事件
    protected function triggerRequestStart($component)
    {
        if ($component->getStatus() == Component::STATUS_READY) {
            $component->onRequestStart();
        }
    }

    // 触发请求结束事件
    protected function triggerRequestEnd($component)
    {
        if ($component->getStatus() == Component::STATUS_RUNNING) {
            $component->onRequestEnd();
        }
    }

    // 获取公开目录路径
    public function getPublicPath()
    {
        return $this->basePath . 'public' . DIRECTORY_SEPARATOR;
    }

    // 获取视图目录路径
    public function getViewPath()
    {
        return $this->basePath . 'views' . DIRECTORY_SEPARATOR;
    }

    // 打印变量的相关信息
    public function dump($var, $send = false)
    {
        ob_start();
        var_dump($var);
        $dumpContent                   = ob_get_clean();
        \Mix::app()->response->content .= $dumpContent;
        if ($send) {
            throw new \mix\exceptions\DebugException(\Mix::app()->response->content);
        }
    }

    // 终止程序
    public function end($content = '')
    {
        throw new \mix\exceptions\EndException($content);
    }

}
