<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Petert82\Monolog\Formatter\LogfmtFormatter;

class CustomizeFormatter
{
    /**
     * Customize the given logger instance.
     *
     * @param  Logger  $logger
     * @return void
     */
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(new TraceContextProcessor());
            $handler->setFormatter(new LogfmtFormatter());
        }
    }
}
