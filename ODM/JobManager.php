<?php

namespace Dtc\QueueBundle\ODM;

use Doctrine\MongoDB\Exception\ResultException;
use Doctrine\MongoDB\Query\Builder;
use Dtc\QueueBundle\Doctrine\DoctrineJobManager;
use Doctrine\ODM\MongoDB\DocumentManager;
use Dtc\QueueBundle\Document\Job;
use Dtc\QueueBundle\Model\BaseJob;
use Dtc\QueueBundle\Util\Util;

class JobManager extends DoctrineJobManager
{
    use CommonTrait;
    const REDUCE_FUNCTION = 'function(k, vals) {
            var result = {};
            for (var index in vals) {
                var val = vals[index];
                for (var i in val) {
                    if (result.hasOwnProperty(i)) {
                        result[i] += val[i];
                    }
                    else {
                        result[i] = val[i];
                    }
                }
            }
            return result;
        }';

    public function countJobsByStatus($objectName, $status, $workerName = null, $method = null)
    {
        /** @var DocumentManager $objectManager */
        $objectManager = $this->getObjectManager();
        $qb = $objectManager->createQueryBuilder($objectName);
        $qb
            ->find()
            ->field('status')->equals($status);

        $this->addWorkerNameCriterion($qb, $workerName, $method);
        $query = $qb->getQuery();

        return $this->runQuery($query, 'count', [], 0);
    }

    /**
     * @param string|null $workerName
     * @param string|null $method
     */
    public function pruneExceptionJobs($workerName = null, $method = null)
    {
        /** @var DocumentManager $objectManager */
        $objectManager = $this->getObjectManager();
        $qb = $objectManager->createQueryBuilder($this->getJobArchiveClass());
        $qb = $qb->remove();
        $qb->field('status')->equals(BaseJob::STATUS_EXCEPTION);
        $this->addWorkerNameCriterion($qb, $workerName, $method);

        $query = $qb->getQuery();
        $result = $this->runQuery($query, 'execute');
        if (isset($result['n'])) {
            return $result['n'];
        }

        return 0;
    }

    /**
     * @param Builder     $builder
     * @param string|null $workerName
     * @param string|null $method
     */
    protected function addWorkerNameCriterion(Builder $builder, $workerName = null, $method = null)
    {
        if (null !== $workerName) {
            $builder->field('workerName')->equals($workerName);
        }

        if (null !== $method) {
            $builder->field('method')->equals($method);
        }
    }

    /**
     * @param null $workerName
     * @param null $method
     *
     * @return int
     */
    protected function updateExpired($workerName = null, $method = null)
    {
        /** @var DocumentManager $objectManager */
        $objectManager = $this->getObjectManager();
        $qb = $objectManager->createQueryBuilder($this->getJobClass());
        $qb = $qb->updateMany();
        $qb->field('expiresAt')->lte(Util::getMicrotimeDateTime());
        $qb->field('status')->equals(BaseJob::STATUS_NEW);
        $this->addWorkerNameCriterion($qb, $workerName, $method);
        $qb->field('status')->set(\Dtc\QueueBundle\Model\Job::STATUS_EXPIRED);
        $query = $qb->getQuery();
        $result = $this->runQuery($query, 'execute');
        if (isset($result['n'])) {
            return $result['n'];
        }

        return 0;
    }

    /**
     * Removes archived jobs older than $olderThan.
     *
     * @param \DateTime $olderThan
     *
     * @return int
     */
    public function pruneArchivedJobs(\DateTime $olderThan)
    {
        return $this->removeOlderThan($this->getJobArchiveClass(), 'updatedAt', $olderThan);
    }

    public function getWaitingJobCount($workerName = null, $method = null)
    {
        /** @var DocumentManager $objectManager */
        $objectManager = $this->getObjectManager();
        $builder = $objectManager->createQueryBuilder($this->getJobClass());
        $builder
            ->find();

        $this->addWorkerNameCriterion($builder, $workerName, $method);
        $this->addStandardPredicates($builder);

        $query = $builder->getQuery();

        return $this->runQuery($query, 'count', [true], 0);
    }

    /**
     * Get Status Jobs.
     *
     * @param string $documentName
     *
     * @return array
     */
    protected function getStatusByDocument($documentName)
    {
        // Run a map reduce function get worker and status break down
        $mapFunc = "function() {
            var result = {};
            result[this.status] = 1;
            var key = this.worker_name + '->' + this.method + '()';
            emit(key, result);
        }";
        $reduceFunc = self::REDUCE_FUNCTION;
        /** @var DocumentManager $objectManager */
        $objectManager = $this->getObjectManager();
        $builder = $objectManager->createQueryBuilder($documentName);
        $builder->map($mapFunc)
            ->reduce($reduceFunc);
        $query = $builder->getQuery();
        $results = $this->runQuery($query, 'execute', [], []);
        $allStatus = static::getAllStatuses();

        $status = [];

        foreach ($results as $info) {
            $status[$info['_id']] = $info['value'] + $allStatus;
        }

        return $status;
    }

    public function getStatus()
    {
        $result = $this->getStatusByDocument($this->getJobClass());
        $status2 = $this->getStatusByDocument($this->getJobArchiveClass());
        foreach ($status2 as $key => $value) {
            foreach ($value as $k => $v) {
                if (isset($result[$key][$k])) {
                    $result[$key][$k] += $v;
                } else {
                    $result[$key][$k] = $v;
                }
            }
        }

        $finalResult = [];
        foreach ($result as $key => $item) {
            ksort($item);
            $finalResult[$key] = $item;
        }

        return $finalResult;
    }

    /**
     * Get the next job to run (can be filtered by workername and method name).
     *
     * @param string      $workerName
     * @param string      $methodName
     * @param bool        $prioritize
     * @param string|null $runId
     *
     * @return \Dtc\QueueBundle\Model\Job
     */
    public function getJob($workerName = null, $methodName = null, $prioritize = true, $runId = null)
    {
        $builder = $this->getJobQueryBuilder($workerName, $methodName, $prioritize);
        $builder
            ->findAndUpdate()
            ->returnNew();

        $date = Util::getMicrotimeDateTime();
        // Update
        $builder
            ->field('startedAt')->set($date)
            ->field('status')->set(BaseJob::STATUS_RUNNING)
            ->field('runId')->set($runId);

        $query = $builder->getQuery();

        $job = $this->runQuery($query, 'execute');

        return $job;
    }

    protected function runQuery(\Doctrine\MongoDB\Query\Query $query, $method, array $arguments = [], $resultIfNamespaceError = null)
    {
        try {
            $result = call_user_func_array([$query, $method], $arguments);
        } catch (ResultException $resultException) {
            if (false === strpos($resultException->getMessage(), 'namespace does not exist') && false === strpos($resultException->getMessage(), 'ns doesn\'t exist')) {
                throw $resultException;
            }
            $result = $resultIfNamespaceError;
        }

        return $result;
    }

    /**
     * @param string|null $workerName
     * @param string|null $methodName
     * @param bool        $prioritize
     *
     * @return Builder
     */
    public function getJobQueryBuilder($workerName = null, $methodName = null, $prioritize = true)
    {
        /** @var DocumentManager $objectManager */
        $objectManager = $this->getObjectManager();
        $builder = $objectManager->createQueryBuilder($this->getJobClass());

        $this->addWorkerNameCriterion($builder, $workerName, $methodName);
        if ($prioritize) {
            $builder->sort([
                'priority' => 'desc',
                'whenAt' => 'asc',
            ]);
        } else {
            $builder->sort('whenAt', 'asc');
        }

        // Filter
        $this->addStandardPredicates($builder);

        return $builder;
    }

    protected function updateNearestBatch(\Dtc\QueueBundle\Model\Job $job)
    {
        /** @var DocumentManager $objectManager */
        $objectManager = $this->getObjectManager();
        $builder = $objectManager->createQueryBuilder($this->getJobClass());
        $builder->find();

        $builder->sort('whenAt', 'asc');
        $builder->field('status')->equals(BaseJob::STATUS_NEW)
            ->field('crcHash')->equals($job->getCrcHash());
        $oldJob = $this->runQuery($builder->getQuery(), 'getSingleResult');

        if (!$oldJob) {
            return null;
        }

        // Update priority or whenAt
        //  This makes sure if someone else is updating at the same time
        //  that we don't trounce their changes.
        $builder = $objectManager->createQueryBuilder($this->getJobClass());
        $builder->findAndUpdate();
        $builder->field('_id')->equals($oldJob->getId());
        $builder->field('priority')->lt($job->getPriority());
        $builder->field('priority')->set($job->getPriority());
        $this->runQuery($builder->getQuery(), 'execute');

        $builder = $objectManager->createQueryBuilder($this->getJobClass());
        $builder->findAndUpdate();
        $builder->field('_id')->equals($oldJob->getId());
        $builder->field('whenAt')->gt($job->getWhenAt());
        $builder->field('whenAt')->set($job->getWhenAt());
        $this->runQuery($builder->getQuery(), 'execute');

        if ($job->getWhenAt() < $oldJob->getWhenAt()) {
            $oldJob->setWhenAt($job->getWhenAt());
        }
        if ($job->getPriority() > $oldJob->getPriority()) {
            $oldJob->setPriority($job->getPriority());
        }

        return $oldJob;
    }

    /**
     * @param mixed $builder
     */
    protected function addStandardPredicates($builder)
    {
        $date = Util::getMicrotimeDateTime();
        $builder
            ->addAnd(
                $builder->expr()->addOr($builder->expr()->field('whenAt')->equals(null), $builder->expr()->field('whenAt')->lte($date)),
                $builder->expr()->addOr($builder->expr()->field('expiresAt')->equals(null), $builder->expr()->field('expiresAt')->gt($date))
            )
            ->field('status')->equals(BaseJob::STATUS_NEW);
    }

    public function getWorkersAndMethods()
    {
        /** @var DocumentManager $documentManager */
        $documentManager = $this->getObjectManager();

        if (!method_exists($documentManager, 'createAggregationBuilder')) {
            return [];
        }

        $aggregationBuilder = $documentManager->createAggregationBuilder($this->getJobClass());

        $this->addStandardPredicates($aggregationBuilder->match());

        $aggregationBuilder->group()
            ->field('id')
            ->expression(
                $aggregationBuilder->expr()
                ->field('workerName')->expression('$workerName')
                ->field('method')->expression('$method')
            );
        $results = $aggregationBuilder->execute()->toArray();

        if (!$results) {
            return [];
        }

        $workersMethods = [];
        foreach ($results as $result) {
            if (isset($result['_id'])) {
                $workersMethods[$result['_id']['worker_name']][] = $result['_id']['method'];
            }
        }

        return $workersMethods;
    }

    /**
     * @param string $workerName
     * @param string $methodName
     */
    public function countLiveJobs($workerName = null, $methodName = null)
    {
        /** @var DocumentManager $objectManager */
        $objectManager = $this->getObjectManager();
        $builder = $objectManager->createQueryBuilder($this->getJobClass());

        $this->addWorkerNameCriterion($builder, $workerName, $methodName);
        // Filter
        $this->addStandardPredicates($builder);

        return $this->runQuery($builder->getQuery(), 'count', [], 0);
    }

    /**
     * @param string        $workerName
     * @param string        $methodName
     * @param callable|null $progressCallback
     */
    public function archiveAllJobs($workerName = null, $methodName = null, callable $progressCallback = null)
    {
        /** @var DocumentManager $documentManager */
        $documentManager = $this->getObjectManager();
        $count = 0;
        $builder = $this->getJobQueryBuilder($workerName, $methodName, true);
        $builder
            ->findAndUpdate()
            ->returnNew();

        $builder->field('status')->set(Job::STATUS_ARCHIVE);
        $query = $builder->getQuery();
        do {
            $job = $this->runQuery($query, 'execute');
            if ($job) {
                $documentManager->remove($job);
                ++$count;

                if (0 == $count % 10) {
                    $this->flush();
                    $this->updateProgress($progressCallback, $count);
                }
            }
        } while ($job);
        $this->flush();
        $this->updateProgress($progressCallback, $count);
    }

    /**
     * @param null $workerName
     * @param null $method
     *
     * @return bool
     */
    public function hasJobInQueue($workerName = null, $method = null)
    {
        $status = [
            BaseJob::STATUS_NEW,
            BaseJob::STATUS_RUNNING,
            BaseJob::STATUS_SUCCESS,
        ];

        return 0 != $this->getJobCount($workerName, $method, $status);
    }
}
