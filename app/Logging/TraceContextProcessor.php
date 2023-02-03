<?php

namespace App\Logging;

use Vinelab\Tracing\Facades\Trace;
use Zipkin\Propagation\TraceContext;

class TraceContextProcessor
{
    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function __invoke(array $record): array
    {
        /** @var TraceContext $traceContext */
        $traceContext = Trace::getCurrentSpan()->getContext()->getRawContext();

        if ($traceContext instanceof TraceContext) {
            $record['extra'] = [
                'TraceID' => $traceContext->getTraceId(),
                'SpanID' => $traceContext->getSpanId(),
            ];
        }

        return $record;
    }
}
