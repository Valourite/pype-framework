<?php

declare(strict_types=1);

namespace Pype\Container;

use Psr\Container\ContainerInterface;

/**
 * For objects you construct yourself (configured stages/formatters, e.g. new View('home'))
 * that still need services. The application hands them the container before use.
 */
interface ContainerAware
{
    public function setContainer(ContainerInterface $container): void;
}
