<?php

namespace App\Logging;

use Vinelab\Tracing\Facades\Trace;

class TraceContextProcessor
{
    public function __invoke(array $record): array
    {
        $currentSpan = Trace::getCurrentSpan()->getContext()->getRawContext();

        $record['extra'] = [
            'TraceID' => $currentSpan->getTraceId(),
            'SpanID' => $currentSpan->getSpanId(),
        ];

        return $record;
    }
}
