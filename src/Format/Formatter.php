<?php

declare(strict_types=1);

namespace Pype\Format;

use Pype\Http\Request;
use Pype\Http\Response;

/**
 * Turns a Response into the bytes sent to the client.
 *
 * Use one per app, group or route by putting it in a stage list — wherever it
 * appears, it is lifted out and applied at the exit of the pipeline:
 *
 *   $router->get('/report', [LoadReport::class, ShowReport::class, CsvFormatter::class]);
 */
interface Formatter
{
    public function format(Response $response, Request $request): string;

    public function contentType(): string;
}
