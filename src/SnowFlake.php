<?php

declare(strict_types=1);

namespace Rabbit\SnowFlake;

use Rabbit\Base\Contract\IdInterface;
use Rabbit\Base\Exception\InvalidConfigException;
use RuntimeException;
use Swoole\Atomic;
use Swoole\Atomic\Long;
use Swoole\Lock;

class SnowFlake implements IdInterface
{
    const PHP_DRIFT = 1;
    const PHP_FLAKE = 2;
    const EXT_DRIFT = 3;
    const EXT_FLAKE = 4;
    /**
     * 基础时间
     */
    protected Long $baseTime;

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
    protected int $maxSeqNumber;

    /**
     * 最小序列数（含）
     */
    protected int $minSeqNumber;

    /**
     * 最大漂移次数
     */
    protected Atomic $topOverCostCount;

    protected int $timestampShift;

    protected Long $lastTimeTick;
    protected Atomic $turnBackTimeTick;
    protected Atomic $turnBackIndex;

    protected Atomic $isOverCost;
    protected Atomic $overCostCountInOneTerm;
    protected Atomic $genCountInOneTerm;
    protected Atomic $termIndex;
    protected bool $lock = false;

    private Atomic $currentSeqNumber;
    private int $method = self::PHP_DRIFT;
    private Lock $swLock;

    public function __construct(Options $options)
    {

        if ($options->baseTime < strtotime("-50 year") || $options->baseTime > microtime(true) * 1000) {
            throw new InvalidConfigException("BaseTime error.");
        }

        if ($options->seqBitLength + $options->workerIdBitLength > 22) {
            throw new InvalidConfigException("error：WorkerIdBitLength + SeqBitLength <= 22");
        }

        $maxWorkerIdNumber = (int)pow(2, $options->workerIdBitLength) - 1;
        if ($options->workerId < 0 || $options->workerId > $maxWorkerIdNumber) {
            throw new InvalidConfigException("WorkerId error. (range:[1, $maxWorkerIdNumber]");
        }

        if ($options->seqBitLength < 2 || $options->seqBitLength > 21) {
            throw new InvalidConfigException("SeqBitLength error. (range:[2, 21])");
        }

        $maxSeqNumber = (int)pow(2, $options->seqBitLength) - 1;
        if ($options->maxSeqNumber < 0 || $options->maxSeqNumber > $maxSeqNumber) {
            throw new InvalidConfigException("MaxSeqNumber error. (range:[1, $maxSeqNumber]");
        }
        if ($options->minSeqNumber < 1 || $options->minSeqNumber > $maxSeqNumber) {
            throw new InvalidConfigException("MinSeqNumber error. (range:[1, $maxSeqNumber]");
        }

        $this->method = $options->method;
        $this->workerId = $options->workerId;
        $this->workerIdBitLength = $options->workerIdBitLength === 0 ? 6 : $options->workerIdBitLength;
        $this->seqBitLength = $options->seqBitLength === 0 ? 6 : $options->seqBitLength;
        $this->maxSeqNumber = $options->maxSeqNumber > 0 ? $options->maxSeqNumber : (int)pow(2, $this->seqBitLength) - 1;
        $this->minSeqNumber = $options->minSeqNumber;
        $this->topOverCostCount = new Atomic($options->topOverCostCount);
        $this->baseTime = new Long($options->baseTime !== 0 ? $options->baseTime : 1582136402000);
        $this->timestampShift = $this->workerIdBitLength + $this->seqBitLength;
        $this->currentSeqNumber = new Atomic($options->minSeqNumber);
        $this->lastTimeTick = new Long(0);
        $this->turnBackTimeTick = new Atomic(0);
        $this->turnBackIndex = new Atomic(0);
        $this->isOverCost = new Atomic(0);
        $this->overCostCountInOneTerm = new Atomic(0);
        $this->genCountInOneTerm = new Atomic(0);
        $this->termIndex = new Atomic(0);
        $this->currentSeqNumber = new Atomic(0);

        $this->lock = $options->lock;
        if ($this->lock) {
            $this->swLock = new Lock(SWOOLE_SPINLOCK);
        }
    }

    private function beginOverCostAction($useTimeTick): void
    {
    }

    private function endOverCostAction(int $useTimeTick): void
    {
        if ($this->termIndex->get() > 10000) {
            $this->termIndex->set(0);
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

        if ($currentTimeTick > $this->lastTimeTick->get()) {
            $this->endOverCostAction($currentTimeTick);

            $this->lastTimeTick->set($currentTimeTick);
            $this->currentSeqNumber->set($this->minSeqNumber);
            $this->isOverCost->set(0);
            $this->overCostCountInOneTerm->set(0);
            $this->genCountInOneTerm->set(0);

            return $this->calcId();
        }

        if ($this->overCostCountInOneTerm >= $this->topOverCostCount) {
            $this->endOverCostAction($currentTimeTick);

            $this->lastTimeTick->set($this->getNextTimeTick());
            $this->currentSeqNumber->set($this->minSeqNumber);
            $this->isOverCost->set(0);
            $this->overCostCountInOneTerm->set(0);
            $this->genCountInOneTerm->set(0);

            return $this->calcId();
        }

        if ($this->currentSeqNumber->get() > $this->maxSeqNumber) {
            $this->lastTimeTick->add();
            $this->currentSeqNumber->set($this->minSeqNumber);
            $this->isOverCost->set(0);
            $this->overCostCountInOneTerm->add();
            $this->genCountInOneTerm->add();

            return $this->calcId();
        }

        $this->genCountInOneTerm->add();
        return $this->calcId();
    }

    private function nextNormalId(): int
    {
        $currentTimeTick = $this->getCurrentTimeTick();

        if ($currentTimeTick < $this->lastTimeTick->get()) {
            if ($this->turnBackTimeTick->get() < 1) {
                $this->turnBackTimeTick->set($this->lastTimeTick->get() - 1);
                $this->turnBackIndex->add();

                // 每毫秒序列数的前5位是预留位，0用于手工新值，1-4是时间回拨次序
                // 最多4次回拨（防止回拨重叠）
                if ($this->turnBackIndex->get() > 4) {
                    $this->turnBackIndex->set(1);
                }
                // $this->beginTurnBackAction($this->turnBackTimeTick);
            }
            return $this->calcTurnBackId();
        }

        // 时间追平时，_TurnBackTimeTick清零
        if ($this->turnBackTimeTick->get() > 0) {
            // $this->endTurnBackAction($this->turnBackTimeTick);
            $this->turnBackTimeTick->set(0);
        }

        if ($currentTimeTick > $this->lastTimeTick->get()) {
            $this->lastTimeTick->set($currentTimeTick);
            $this->currentSeqNumber->set($this->minSeqNumber);

            return $this->calcId();
        }

        if ($this->currentSeqNumber->get() > $this->maxSeqNumber) {
            // $this->beginOverCostAction($currentTimeTick);

            $this->termIndex->add();
            $this->lastTimeTick->add();
            $this->currentSeqNumber->set($this->minSeqNumber);
            $this->isOverCost->set(1);
            $this->overCostCountInOneTerm->set(1);
            $this->genCountInOneTerm->set(1);

            return $this->calcId();
        }

        return $this->calcId();
    }

    private function calcId(): int
    {
        $result = (($this->lastTimeTick->get() << $this->timestampShift) +
            ($this->workerId << $this->seqBitLength) + $this->currentSeqNumber->get());

        $this->currentSeqNumber->add();
        return $result;
    }

    private function calcTurnBackId(): int
    {
        $result = (($this->turnBackTimeTick->get() << $this->timestampShift) +
            ($this->workerId << $this->seqBitLength) + $this->turnBackIndex->get());

        $this->turnBackTimeTick->sub();
        return $result;
    }

    protected function getCurrentTimeTick(): int
    {
        $millis = (int)(microtime(true) * 1000);
        return $millis - $this->baseTime->get();
    }

    protected function getNextTimeTick(): int
    {
        $tempTimeTicker = $this->getCurrentTimeTick();

        while ($tempTimeTicker <= $this->lastTimeTick) {
            $tempTimeTicker = $this->getCurrentTimeTick();
        }

        return $tempTimeTicker;
    }

    /**
     * @return int
     * @throws Throwable
     */
    private function getId(): int
    {
        $currentTimeTick = $this->getCurrentTimeTick();
        if ($this->lastTimeTick->get() === $currentTimeTick) {
            if (0 === $this->currentSeqNumber->add() & $this->maxSeqNumber) {
                $this->currentSeqNumber->set(0);
                $currentTimeTick = $this->getNextTimeTick();
            }
        } else {
            $this->currentSeqNumber->set($this->minSeqNumber);
        }

        if ($currentTimeTick < $this->lastTimeTick->get()) {
            throw new RuntimeException("Time error for " . $this->lastTimeTick->get() - $currentTimeTick . "milliseconds");
        }

        $this->lastTimeTick->set($currentTimeTick);
        return ($currentTimeTick << $this->timestampShift) + ($this->workerId << $this->seqBitLength) + $this->currentSeqNumber->get();
    }

    public function nextId(): int
    {
        switch ($this->method) {
            case self::PHP_FLAKE:
                if ($this->lock) {
                    $this->swLock->lock();
                    $id = $this->getId();
                    $this->swLock->unlock();
                    return $id;
                }
                return $this->getId();
            case self::EXT_DRIFT:
                return (int)\SnowDrift::NextId();
            case self::EXT_FLAKE:
                return (int)\SnowFlake::getId();
            case self::PHP_DRIFT:
            default:
                if ($this->lock) {
                    $this->swLock->lock();
                    $id = $this->isOverCost->get() === 1 ? $this->nextOverCostId() : $this->nextNormalId();
                    $this->swLock->unlock();
                    return $id;
                }
                return $this->isOverCost->get() === 1 ? $this->nextOverCostId() : $this->nextNormalId();
        }
    }
}
