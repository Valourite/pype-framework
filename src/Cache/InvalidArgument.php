<?php

declare(strict_types=1);

namespace Pype\Cache;

/** A key PSR-16 doesn't allow: empty, or containing one of {}()/\@: */
final class InvalidArgument extends \InvalidArgumentException implements \Psr\SimpleCache\InvalidArgumentException
{
}
