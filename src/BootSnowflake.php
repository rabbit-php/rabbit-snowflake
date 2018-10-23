<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/10/13
 * Time: 3:10
 */

namespace rabbit\snowflake;

use rabbit\App;
use rabbit\server\BootInterface;

/**
 * Class BootSnowflake
 * @package rabbit\snowflake
 */
class BootSnowflake implements BootInterface
{
    public function handle(): void
    {
        $swooleServer = App::getServer();
        //创建自旋锁
        $swooleServer->snowLock = new \Swoole\Lock(SWOOLE_SPINLOCK);
        $swooleServer->lastTimestamp = 0;

        //设置原子计数
        $swooleServer->snowAtomic = new Atomic(0);
    }
}