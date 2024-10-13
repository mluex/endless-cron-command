<?php

namespace Mluex\EndlessCronCommand\Command;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Wrep\Daemonizable\Command\EndlessCommand;

class EndlessCronCommand extends EndlessCommand
{
    private const DEFAULT_LOCK_TTL = 60.0;

    private ?LockInterface $lock = null;
    private ?int $startTime = null;
    private ?int $runtime = null;
    private ?int $lockTtl = null;

    public function __construct(
        private readonly LockFactory $lockFactory,
        string $name = null
    ) {
        parent::__construct($name);

        $this->addOption(
            'runtime',
            null,
            InputOption::VALUE_OPTIONAL,
            'Time (sec) after which the command will gracefully shutdown.'
        );

        $this->addOption(
            'frequency',
            null,
            InputOption::VALUE_OPTIONAL,
            'Frequency (sec) of the main loop.',
            EndlessCommand::DEFAULT_TIMEOUT
        );

        $this->addOption(
            'lock-ttl',
            null,
            InputOption::VALUE_OPTIONAL,
            'TTL (sec) of the lock.',
            self::DEFAULT_LOCK_TTL
        );
    }

    /**
     * @inheritdoc
     */
    protected function starting(InputInterface $input, OutputInterface $output): void
    {
        $this->startTime = time();

        $frequency = $input->getOption('frequency');
        if (!is_numeric($frequency) || (int) $frequency < 1) {
            throw new RuntimeException('Frequency must be a positive number.');
        }
        $this->setTimeout((float) $frequency);

        $runtime = $input->getOption('runtime');
        if (null !== $runtime && (!is_numeric($runtime) || $runtime < 1)) {
            throw new RuntimeException('Runtime must be a positive number or empty.');
        }
        $this->runtime = null !== $runtime ? (int) $runtime : null;

        $lockTtl = $input->getOption('lock-ttl');
        if (!is_numeric($lockTtl) || (int) $lockTtl < 1) {
            throw new RuntimeException('Lock TTL must be a positive number.');
        }
        $this->lockTtl = (float) $lockTtl;

        parent::starting($input, $output);
    }

    protected function die(OutputInterface $output, string $msg): int
    {
        $this->shutdown();
        $this->releaseLock();

        $this->setReturnCode(Command::FAILURE);

        return $this->failedIteration($output, $msg);
    }

    protected function failedIteration(OutputInterface $output, ?string $msg = null): int
    {
        if (null !== $msg && $output->getVerbosity() > OutputInterface::VERBOSITY_QUIET) {
            $output->writeln(sprintf('<error>%s</error>', $msg));
        }

        return Command::FAILURE;
    }

    /**
     * @noinspection PhpUnused
     */
    protected function successfulIteration(OutputInterface $output): int
    {
        return Command::SUCCESS;
    }

    /**
     * @inheritdoc
     */
    protected function startIteration(InputInterface $input, OutputInterface $output): void
    {
        /*
         * It would be cleaner to catch any lock related exceptions here and shutdown the command properly by calling
         * die() method. But unfortunately startIteration can only stop the command by throwing an exception.
         */
        $this->acquireLock($input);
    }

    /**
     * @inheritdoc
     */
    protected function finishIteration(InputInterface $input, OutputInterface $output): void
    {
        $this->throwExceptionOnShutdown();

        if ($this->isGracefulShutdownDue()) {
            $this->shutdownGracefully($output);
        }
    }

    private function shutdownGracefully(OutputInterface $output): void
    {
        $this->shutdown();
        $this->releaseLock();

        if ($output->getVerbosity() > OutputInterface::VERBOSITY_QUIET) {
            $output->writeln(sprintf(
                '<info>Graceful shutting down after %d seconds.</info>',
                $this->getElapsedTime()
            ));
        }
    }

    private function isGracefulShutdownDue(): bool
    {
        $elapsed = $this->getElapsedTime();

        return null !== $this->runtime && null !== $elapsed && $elapsed >= $this->runtime;
    }

    private function getElapsedTime(): ?int
    {
        return null !== $this->startTime ? time() - $this->startTime : null;
    }

    /**
     * @noinspection PhpUnused
     */
    protected function clearFingersCrossedLogHandlers(mixed $logger): void
    {
        if (!(is_a($logger, '\Monolog\Logger'))) {
            return;
        }

        foreach ($logger->getHandlers() as $handler) {
            if (!is_a($handler, '\Monolog\Handler\FingersCrossedHandler')) {
                continue;
            }

            $handler->clear();
        }
    }

    /**
     * @noinspection PhpUnusedParameterInspection
     */
    protected function createLockKey(InputInterface $input): string
    {
        return $this->getName() ?? get_class($this);
    }

    private function acquireLock(InputInterface $input): void
    {
        if ((bool) $input->getOption('run-once')) {
            return; // No locking needed
        }

        if (null === $this->lock) {
            $this->lock = $this->lockFactory->createLock($this->createLockKey($input), ttl: $this->lockTtl);
        }

        if (!$this->lock->acquire()) {
            throw new RuntimeException('Another instance is already running.');
        }

        $this->lock->refresh($this->lockTtl);
    }

    private function releaseLock(): void
    {
        if (null === $this->lock) {
            return;
        }

        $this->lock->release();
    }
}
