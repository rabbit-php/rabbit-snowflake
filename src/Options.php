<?php

declare(strict_types=1);

namespace Rabbit\SnowFlake;

class Options
{
    /**
     * 雪花计算方法
     * （1-漂移算法|2-传统算法），默认1
     */
    public int $method = 1;

    /**
     * 开始时间
     * 不能超过当前系统时间
     */
    public int $baseTime = 1582136402000;

    /**
     * 机器码，必须由外部系统设置
     * 与 WorkerIdBitLength 有关系
     * （short类型，最大值32766，如果有更高要求，请修改数据类型，或联系作者)
     */
    public int $workerId = 0;

    /**
     * 机器码位长
     * 范围：1-21（要求：序列数位长+机器码位长不超过22）。
     * 建议范围：6-12。
     */
    public int $workerIdBitLength = 6;

    /**
     * 序列数位长
     * 范围：2-21（要求：序列数位长+机器码位长不超过22）。
     * 建议范围：6-14。
     */
    public int $seqBitLength = 6;

    /**
     * 最大序列数（含）
     * （由SeqBitLength计算的最大值）
     */
    public int $maxSeqNumber = 0;

    /**
     * 最小序列数（含）
     * 默认5，不小于1，不大于MaxSeqNumber
     */
    public int $minSeqNumber = 5;

    public bool $lock = false;
    /**
     * 最大漂移次数（含）
     * 默认2000，推荐范围500-10000（与计算能力有关）
     */
    public int $topOverCostCount = 2000;
    public function __construct(array $config)
    {
        foreach ($config as $name => $val) {
            if (property_exists($this, $name)) {
                $this->$name = $val;
            }
        }
    }
}
