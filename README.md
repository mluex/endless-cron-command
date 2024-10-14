# Endless Cron Commands for Symfony

This package is a small extension of [wrep/daemonizable-command](https://packagist.org/packages/wrep/daemonizable-command). If no Upstart or systemd are available to set up a daemon, a simple cronjob and Symfony Lock are sufficient to produce an almost similar result.

## Detailed information on Daemonizable / Endless Commands
Check out the documentation of wrep/daemonizable-command for more information on how to create endless commands: [wrep/daemonizable-command](https://packagist.org/packages/wrep/daemonizable-command)

## Installation

`composer require mluex/endless-cron-command`

### Which version to use?
* Version 5.* for Symfony 7 and higher
* Version 4.* for Symfony 6

## Usage

### 1. Configuration
EndlessCronCommands require symfony/lock to ensure that only one instance is executed at a time. Please configure the bundle accordingly in your .env.local, e.g.
    
```dotenv
###> symfony/lock ###
LOCK_STORE_DSN=mysql:host=127.0.0.1;dbname=app
###< symfony/lock ###
```

⚠️ Make sure to use a lock store that supports expiry! Check the [Symfony Lock documentation](https://symfony.com/doc/current/components/lock.html) for more information.

### 2. Implementation

Create a Symfony command that extends `EndlessCronCommand` and off you go. Here is a minimal example:

```php
namespace Acme\DemoBundle\Command;

use Mluex\EndlessCronCommand\EndlessCronCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MinimalDemoCommand extends EndlessCronCommand
{
    // This is just a normal Command::configure() method
    protected function configure(): void
    {
        $this->setName('acme:minimaldemo')
            ->setDescription('An EndlessCronCommand implementation example');
    }

    // Method will be called in an endless loop
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Do some work
        file_put_contents('/tmp/acme-timestamp.txt', time());
    }
}
```

If your command includes Monolog's FingersCrossed Log Handlers, you may want to clear them after every iteration as wrep pointed out in his [documentation](https://github.com/mac-cain13/daemonizable-command?tab=readme-ov-file#memory-usage-and-leaks). EndlessCronCommand provides a method for this:

```php
namespace Acme\DemoBundle\Command;

use Mluex\EndlessCronCommand\EndlessCronCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MinimalDemoCommand extends EndlessCronCommand
{
    public function __construct(
        private readonly LoggerInterface $logger,
        string $name = null
    ) {
        parent::__construct($name);
    }

    // This is just a normal Command::configure() method
    protected function configure(): void
    {
        $this->setName('acme:minimaldemo')
            ->setDescription('An EndlessCronCommand implementation example');
    }

    // Method will be called in an endless loop
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Do some work
        file_put_contents('/tmp/acme-timestamp.txt', time());
        
        // Clear FingersCrossed Log Handlers
        $this->clearFingersCrossedLogHandlers($this->logger);
    }
}
```
    

### 3. Cronjob setup

Add a cronjob to your crontab to run the command every minute.

```bash
* * * * * php /path/to/your/project/bin/console acme:minimaldemo >/dev/null 2>&1
```

### 4. Advanced usage

It may make sense to limit the runtime of an instance and restart the command regularly. Use the runtime option for this:

```bash
* * * * * php /path/to/your/project/bin/console acme:minimaldemo --runtime=600 >/dev/null 2>&1
```

In order to run the command's execute() method more often in the main loop (default is 5 sec), you can use the frequency option to set a lower timeout between iterations (1 sec in this example):

```bash
* * * * * php /path/to/your/project/bin/console acme:minimaldemo --frequency=1 >/dev/null 2>&1
```

Every iteration will refresh the lock and push its expiry time further into the future. If the command crashes, the lock will expire after the lock-ttl time and the next cronjob will start a new instance. You can set the lock-ttl to a lower value to ensure that the command is restarted more quickly after a crash:

```bash
* * * * * php /path/to/your/project/bin/console acme:minimaldemo --lock-ttl=60 >/dev/null 2>&1
```

## TODO
- [ ] Examples
- [ ] Unit Tests