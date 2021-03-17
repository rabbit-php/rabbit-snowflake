<?php

declare(strict_types=1);

namespace Rabbit\SnowFlake;

use Rabbit\Base\Contract\IdInterface;

class SnowFlakeDrift implements IdInterface
{
    /**
     * 基础时间
     */
    protected int $baseTime;

    /**
     * 机器码
     */
    protected int $workerId;

    /**
     * 机器码位长
     */
    protected int $workerIdBitLength;

    /**
     * 自增序列数位长
     */
    protected int $seqBitLength;

    /**
     * 最大序列数（含）
     */
    protected  int $maxSeqNumber;

    /**
     * 最小序列数（含）
     */
    protected int $minSeqNumber;

    /**
     * 最大漂移次数
     */
    protected int $topOverCostCount;

    protected int $timestampShift;

    protected int $currentSeqNumber;
    protected int $lastTimeTick = -1;
    protected int $turnBackTimeTick = -1;
    protected int $turnBackIndex = 0;

    protected bool $isOverCost = false;
    protected int $overCostCountInOneTerm = 0;
    protected int $genCountInOneTerm = 0;
    protected int $termIndex = 0;

    public function __construct(Options $options)
    {
        $this->workerId = $options->workerId;
        $this->workerIdBitLength = $options->workerIdBitLength === 0 ? 6 : $options->workerIdBitLength;
        $this->seqBitLength = $options->seqBitLength === 0 ? 6 : $options->seqBitLength;
        $this->maxSeqNumber = $options->maxSeqNumber > 0 ? $options->maxSeqNumber : pow(2, $this->seqBitLength) - 1;
        $this->minSeqNumber = $options->minSeqNumber;
        $this->topOverCostCount = $options->topOverCostCount;
        $this->baseTime = $options->baseTime !== 0 ? $options->baseTime : 1582136402000;
        $this->timestampShift = $this->workerIdBitLength + $this->seqBitLength;
        $this->currentSeqNumber = $options->minSeqNumber;
    }

    private function beginOverCostAction($useTimeTick): void
    {
    }

    private function endOverCostAction(int $useTimeTick): void
    {
        if ($this->termIndex > 10000) {
            $this->termIndex = 0;
        }
    }

    private function beginTurnBackAction($useTimeTick): void
    {
    }

    private function endTurnBackAction($useTimeTick): void
    {
    }

    private function nextOverCostId(): int
    {
        $currentTimeTick = $this->getCurrentTimeTick();

        if ($currentTimeTick > $this->lastTimeTick) {
            $this->endOverCostAction($currentTimeTick);

            $this->lastTimeTick = $currentTimeTick;
            $this->currentSeqNumber = $this->minSeqNumber;
            $this->isOverCost = false;
            $this->overCostCountInOneTerm = 0;
            $this->genCountInOneTerm = 0;

            return $this->calcId($this->lastTimeTick);
        }

        if ($this->overCostCountInOneTerm >= $this->topOverCostCount) {
            $this->endOverCostAction($currentTimeTick);

            $this->lastTimeTick = $this->getNextTimeTick();
            $this->currentSeqNumber = $this->minSeqNumber;
            $this->isOverCost = false;
            $this->overCostCountInOneTerm = 0;
            $this->genCountInOneTerm = 0;

            return $this->calcId($this->lastTimeTick);
        }

        if ($this->currentSeqNumber > $this->maxSeqNumber) {
            $this->lastTimeTick++;
            $this->currentSeqNumber = $this->minSeqNumber;
            $this->isOverCost = true;
            $this->overCostCountInOneTerm++;
            $this->genCountInOneTerm++;

            return $this->calcId($this->lastTimeTick);
        }

        $this->genCountInOneTerm++;
        return $this->calcId($this->lastTimeTick);
    }

    private function nextNormalId(): int
    {
        $currentTimeTick = $this->getCurrentTimeTick();

        if ($currentTimeTick < $this->lastTimeTick) {
            if ($this->turnBackTimeTick < 1) {
                $this->turnBackTimeTick = $this->lastTimeTick - 1;
                $this->turnBackIndex++;

                // 每毫秒序列数的前5位是预留位，0用于手工新值，1-4是时间回拨次序
                // 最多4次回拨（防止回拨重叠）
                if ($this->turnBackIndex > 4) {
                    $this->turnBackIndex = 1;
                }
                // $this->beginTurnBackAction($this->turnBackTimeTick);
            }

            usleep(10 * 1000);
            return $this->calcTurnBackId($this->turnBackTimeTick);
        }

        // 时间追平时，_TurnBackTimeTick清零
        if ($this->turnBackTimeTick > 0) {
            // $this->endTurnBackAction($this->turnBackTimeTick);
            $this->turnBackTimeTick = 0;
        }

        if ($currentTimeTick > $this->lastTimeTick) {
            $this->lastTimeTick = $currentTimeTick;
            $this->currentSeqNumber = $this->minSeqNumber;

            return $this->calcId($this->lastTimeTick);
        }

        if ($this->currentSeqNumber > $this->maxSeqNumber) {
            // $this->beginOverCostAction($currentTimeTick);

            $this->termIndex++;
            $this->lastTimeTick++;
            $this->currentSeqNumber = $this->minSeqNumber;
            $this->isOverCost = true;
            $this->overCostCountInOneTerm = 1;
            $this->genCountInOneTerm = 1;

            return $this->calcId($this->lastTimeTick);
        }

        return $this->calcId($this->lastTimeTick);
    }

    private function calcId(int $useTimeTick): int
    {
        $result = (($useTimeTick << $this->timestampShift) +
            ($this->workerId << $this->seqBitLength) + $this->currentSeqNumber);

        $this->currentSeqNumber++;
        return $result;
    }

    private function calcTurnBackId(int $useTimeTick): int
    {
        $result = (($useTimeTick << $this->timestampShift) +
            ($this->workerId << $this->seqBitLength) + $this->turnBackIndex);

        $this->turnBackTimeTick--;
        return $result;
    }

    protected function getCurrentTimeTick(): int
    {
        $millis = microtime(true) * 1000;
        return (int)($millis - $this->baseTime);
    }

    protected function getNextTimeTick(): int
    {
        $tempTimeTicker = $this->getCurrentTimeTick();

        while ($tempTimeTicker <= $this->lastTimeTick) {
            $tempTimeTicker = $this->getCurrentTimeTick();
        }

        return $tempTimeTicker;
    }

    public function nextId(): int
    {
        return $this->isOverCost ? $this->nextOverCostId() : $this->nextNormalId();
    }
}
