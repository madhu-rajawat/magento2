<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\MessageQueue\Model\Cron;

use Magento\Framework\ShellInterface;
use Magento\Framework\MessageQueue\Consumer\ConfigInterface as ConsumerConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Symfony\Component\Process\PhpExecutableFinder;
use Magento\MessageQueue\Model\Cron\ConsumersRunner\PidConsumerManager;

/**
 * Class for running consumers processes by cron
 */
class ConsumersRunner
{
    /**
     * Shell command line wrapper for executing command in background
     *
     * @var ShellInterface
     */
    private $shellBackground;

    /**
     * Consumer config provider
     *
     * @var ConsumerConfigInterface
     */
    private $consumerConfig;

    /**
     * Application deployment configuration
     *
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * The executable finder specifically designed for the PHP executable
     *
     * @var PhpExecutableFinder
     */
    private $phpExecutableFinder;

    /**
     * The class for checking status of process by PID
     *
     * @var PidConsumerManager
     */
    private $pidConsumerManager;

    /**
     * @param PhpExecutableFinder $phpExecutableFinder The executable finder specifically designed
     *        for the PHP executable
     * @param ConsumerConfigInterface $consumerConfig The consumer config provider
     * @param DeploymentConfig $deploymentConfig The application deployment configuration
     * @param ShellInterface $shellBackground The shell command line wrapper for executing command in background
     * @param PidConsumerManager $pidConsumerManager The class for checking status of process by PID
     */
    public function __construct(
        PhpExecutableFinder $phpExecutableFinder,
        ConsumerConfigInterface $consumerConfig,
        DeploymentConfig $deploymentConfig,
        ShellInterface $shellBackground,
        PidConsumerManager $pidConsumerManager
    ) {
        $this->phpExecutableFinder = $phpExecutableFinder;
        $this->consumerConfig = $consumerConfig;
        $this->deploymentConfig = $deploymentConfig;
        $this->shellBackground = $shellBackground;
        $this->pidConsumerManager = $pidConsumerManager;
    }

    /**
     * Runs consumers processes
     */
    public function run()
    {
        $runByCron = $this->deploymentConfig->get('queue_consumer/cron_run', true);

        if (!$runByCron) {
            return;
        }

        $maxMessages = (int) $this->deploymentConfig->get('queue_consumer/max_messages', 10000);
        $allowedConsumers = $this->deploymentConfig->get('queue_consumer/consumers', []);
        $php = $this->phpExecutableFinder->find() ?: 'php';

        foreach ($this->consumerConfig->getConsumers() as $consumer) {
            $consumerName = $consumer->getName();

            if (!$this->canBeRun($consumerName, $allowedConsumers)) {
                continue;
            }

            $arguments = [
                $consumerName,
                '--pid-file-path=' . $this->pidConsumerManager->getPidFilePath($consumerName),
            ];

            if ($maxMessages) {
                $arguments[] = '--max-messages=' . $maxMessages;
            }

            $command = $php . ' ' . BP . '/bin/magento queue:consumers:start %s %s'
                . ($maxMessages ? ' %s' : '');

            $this->shellBackground->execute($command, $arguments);
        }
    }

    /**
     * Checks that the consumer can be run
     *
     * @param string $consumerName The consumer name
     * @param array $allowedConsumers The list of allowed consumers
     *        If $allowedConsumers is empty it means that all consumers are allowed
     * @return bool Returns true if the consumer can be run
     */
    private function canBeRun($consumerName, array $allowedConsumers = [])
    {
        $allowed = empty($allowedConsumers) ?: in_array($consumerName, $allowedConsumers);

        return $allowed && !$this->pidConsumerManager->isRun($consumerName);
    }
}
