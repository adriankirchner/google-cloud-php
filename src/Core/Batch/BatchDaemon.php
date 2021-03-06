<?php
/**
 * Copyright 2017 Google Inc. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Cloud\Core\Batch;

/**
 * An external daemon script for executing the batch jobs.
 *
 * @codeCoverageIgnore
 *
 * The system test is responsible for testing this class.
 * {@see \Google\Cloud\Tests\System\Core\Batch}
 */
class BatchDaemon
{
    use SysvTrait;
    use HandleFailureTrait;

    /* @var BatchRunner */
    private $runner;
    
    /* @var bool */
    private $shutdown;

    /* @var array */
    private $descriptorSpec;

    /* @var string */
    private $command;

    /**
     * Prepare the descriptor spec and install signal handlers.
     *
     * @param string $entrypoint Daemon's entrypoint script.
     */
    public function __construct($entrypoint)
    {
        if (! $this->isSysvIPCLoaded()) {
            throw new \RuntimeException('SystemV IPC exntensions are missing.');
        }
        $this->runner = new BatchRunner(
            new SysvConfigStorage(),
            new SysvSubmitter()
        );
        $this->shutdown = false;
        // Just share the usual descriptors.
        $this->descriptorSpec = [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w']
        ];
        // setup signal handlers
        pcntl_signal(SIGTERM, [$this, "sigHandler"]);
        pcntl_signal(SIGINT, [$this, "sigHandler"]);
        pcntl_signal(SIGHUP, [$this, "sigHandler"]);
        pcntl_signal(SIGALRM, [$this, "sigHandler"]);
        $this->command = sprintf('exec php -d auto_prepend_file="" %s', $entrypoint);
        $this->initFailureFile();
    }

    /**
     * A signal handler for setting the terminate switch.
     * {@see http://php.net/manual/en/function.pcntl-signal.php}
     *
     * @param int $signo The received signal.
     * @param mixed $siginfo [optional] An array representing the signal
     *              information. **Defaults to** null.
     *
     * @return void
     */
    public function sigHandler($signo, $signinfo = null)
    {
        switch ($signo) {
            case SIGINT:
            case SIGTERM:
                $this->shutdown = true;
                break;
        }
    }

    /**
     * A loop for the parent.
     *
     * @return void
     */
    public function runParent()
    {
        $procs = [];
        while (true) {
            $jobs = $this->runner->getJobs();
            foreach ($jobs as $job) {
                if (! array_key_exists($job->getIdentifier(), $procs)) {
                    echo 'Spawning children' . PHP_EOL;
                    $procs[$job->getIdentifier()] = [];
                    for ($i = 0; $i < $job->getWorkerNum(); $i++) {
                        $procs[$job->getIdentifier()][] = proc_open(
                            sprintf('%s %d', $this->command, $job->getIdNum()),
                            $this->descriptorSpec,
                            $pipes
                        );
                    }
                }
            }
            pcntl_signal_dispatch();
            if ($this->shutdown) {
                echo 'Shutting down, waiting for the children' . PHP_EOL;
                foreach ($procs as $k => $v) {
                    foreach ($v as $proc) {
                        $status = proc_get_status($proc);
                        // Keep sending SIGTERM until the child exits.
                        while ($status['running'] === true) {
                            @proc_terminate($proc);
                            usleep(50000);
                            $status = proc_get_status($proc);
                        }
                        @proc_close($proc);
                    }
                }
                echo 'BatchDaemon exiting' . PHP_EOL;
                exit;
            }
            usleep(1000000); // Reload the config after 1 second
            // Reload the config
            $this->runner->loadConfig();
        }
    }

    /**
     * A loop for the children.
     *
     * @param int $idNum Numeric id for the job.
     * @return void
     */
    public function runChild($idNum)
    {
        // child process
        $sysvKey = $this->getSysvKey($idNum);
        $q = msg_get_queue($sysvKey);
        $items = [];
        $job = $this->runner->getJobFromIdNum($idNum);
        $period = $job->getCallPeriod();
        $lastInvoked = microtime(true);
        $batchSize = $job->getBatchSize();
        while (true) {
            // Fire SIGALRM after 1 second to unblock the blocking call.
            pcntl_alarm(1);
            if (msg_receive(
                $q,
                0,
                $type,
                8192,
                $message,
                true,
                0, // blocking mode
                $errorcode
            )) {
                if ($type === self::$typeDirect) {
                    $items[] = $message;
                } elseif ($type === self::$typeFile) {
                    $items[] = unserialize(file_get_contents($message));
                    @unlink($message);
                }
            }
            pcntl_signal_dispatch();
            // It runs the job when
            // 1. Number of items reaches the batchSize.
            // 2-a. Count is >0 and the current time is larger than lastInvoked + period.
            // 2-b. Count is >0 and the shutdown flag is true.
            if ((count($items) >= $batchSize)
                || (count($items) > 0
                    && (microtime(true) > $lastInvoked + $period
                        || $this->shutdown))) {
                printf(
                    'Running the job with %d items' . PHP_EOL,
                    count($items)
                );
                if (! $job->run($items)) {
                    $this->handleFailure($idNum, $items);
                }
                $items = [];
                $lastInvoked = microtime(true);
            }
            gc_collect_cycles();
            if ($this->shutdown) {
                exit;
            }
        }
    }
}
