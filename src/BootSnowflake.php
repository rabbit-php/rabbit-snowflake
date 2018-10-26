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
use Swoole\Atomic;

/**
 * Class BootSnowflake
 * @package rabbit\snowflake
 */
class BootSnowflake implements BootInterface
{
    public function handle(): void
    {
        $app = App::getApp();
        //创建自旋锁
        $app->snowLock = new \Swoole\Lock(SWOOLE_SPINLOCK);
        $app->lastTimestamp = 0;

        //设置原子计数
        $app->snowAtomic = new Atomic(0);
    }
}