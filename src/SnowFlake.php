<?php

declare(strict_types=1);

namespace Rabbit\SnowFlake;

use RuntimeException;
use Swoole\Atomic;
use Throwable;

/**
 * Class SnowFlake
 * @package Rabbit\SnowFlake
 */
class SnowFlake extends SnowFlakeDrift
{
    const EXT_NO = 0;
    const EXT_SNOWDRIFT = 1;
    const EXT_SNOWFLAKE = 2;
    private ?Atomic $atomic = null;
    private int $useExt = 0;

    /**
     * SnowFlake constructor.
     * @param int $workerId
     */
    public function __construct(Options $options)
    {
        parent::__construct($options);
        if ($this->useExt === self::EXT_SNOWFLAKE && extension_loaded('snowflake')) {
            $workerBits = (int)ini_get('snowflake.worker_bits');
            $regionBits = (int)ini_get('snowflake.region_bits');
            $workerId = 1;
            $regionId = 0;
            for ($i = 1; $i < $workerBits; $i++) {
                $regionId = $workerId = $workerId << 1 ^ 1;
            }
            for ($i = 0; $i < $regionBits; $i++) {
                $regionId = $regionId << 1;
            }
            ini_set('snowflake.worker_id', (string)($this->workerId & $workerId));
            ini_set('snowflake.region_id', (string)($this->workerId & $regionId));
        } elseif ($this->useExt === self::EXT_SNOWDRIFT && extension_loaded('snowdrift')) {
            ini_set('snowflake.Method', '1');
            ini_set('snowflake.BaseTime', (string)$this->baseTime);
            ini_set('snowflake.WorkerId', (string)$this->workerId);
            ini_set('snowflake.WorkerIdBitLength', (string)$this->workerIdBitLength);
            ini_set('snowflake.SeqBitLength', (string)$this->seqBitLength);
            ini_set('snowflake.TopOverCostCount', (string)$this->topOverCostCount);
            ini_set('snowflake.Lock', (string)$this->lock);
        } else {
            $this->atomic = new Atomic(0);
        }
    }

    /**
     * @return int
     * @throws Throwable
     */
    private function getId(): int
    {
        $currentTime = $this->getCurrentTimeTick();
        if ($this->lastTimeTick === $currentTime) {
            if ($this->atomic->add() > $this->maxSeqNumber) {
                $this->atomic->set(0);
                $currentTime = $this->getCurrentTimeTick();
            }
        } else {
            $this->atomic->set(0);
        }

        if ($currentTime < $this->lastTimeTick) {
            throw new RuntimeException("Time error for " . $this->lastTimeTick - $currentTime . "milliseconds");
        }

        $this->lastTimeTick = $currentTime;
        return ($currentTime << $this->timestampShift) + ($this->workerId << $this->seqBitLength) + $this->atomic->get();
    }

    /**
     * @return int|mixed
     * @throws Throwable
     */
    public function nextId(): int
    {
        if ($this->useExt === self::EXT_SNOWFLAKE) {
            return (int)\SnowFlake::getId();
        } elseif ($this->useExt === self::EXT_SNOWDRIFT) {
            return (int)\SnowDrift::getId();
        }
        return (int)$this->getId();
    }
}
