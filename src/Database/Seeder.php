<?php

declare(strict_types=1);

namespace Pype\Database;

use Psr\Container\ContainerInterface;
use Pype\Console\Input;
use Pype\Container\ContainerAware;
use Pype\Pipeline\Invoker;

/**
 * Fills the database: `php bin/pype db:seed` runs Database\Seeders\DatabaseSeeder (or --class).
 * Write a run() method; its parameters are injected like a command's (Connection, Auth, Input
 * for --options...). Run other seeders with $this->call().
 *
 *   final class DatabaseSeeder extends Seeder
 *   {
 *       public function run(Connection $db): void
 *       {
 *           UserFactory::new($db)->count(10)->create();
 *           $this->call(DemoDataSeeder::class);
 *       }
 *   }
 */
abstract class Seeder implements ContainerAware
{
    private ?ContainerInterface $container = null;

    private ?Input $input = null;

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    /** Run this seeder's run() with its parameters filled in (the command does this). */
    public function __invoke(Input $input): mixed
    {
        $this->input = $input;
        $container = $this->container ?? throw new \LogicException('A Seeder needs the container: run it with bin/pype db:seed.');

        return (new Invoker($container))->call([$this, 'run'], $input, static fn () => null);
    }

    /** Run other seeders, with the same command-line options. */
    protected function call(string ...$seeders): void
    {
        $container = $this->container ?? throw new \LogicException('A Seeder needs the container: run it with bin/pype db:seed.');

        foreach ($seeders as $class) {
            $seeder = $container->get($class);
            $seeder->setContainer($container);
            $seeder($this->input ?? new Input([]));
        }
    }
}
