<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Vinelab\Tracing\Facades\Trace;
use Vinelab\Tracing\Propagation\Formats;

/**
 * @internal   Just for a test
 * @deprecated Remove this class
 */
class TestController extends \Illuminate\Routing\Controller
{
    /**
     * Test database reading/writing using eloquent model.
     *
     * @return JsonResponse
     */
    public function database(): JsonResponse
    {
        Log::info('Processing request to connect to the database with User operations');

        $started_at    = \microtime(true);
        $memory_bytes  = \memory_get_usage();
        $random_string = Str::random();

        $user           = new User();
        $user->name     = "foo_${random_string}";
        $user->email    = "foo_${random_string}@example.com";
        $user->password = 'bar';

        if (!$user->save()) {
            return new JsonResponse([
                'success' => false,
                'error'   => 'cannot save the model',
            ], 500);
        }

        if (!$user->delete()) {
            return new JsonResponse([
                'success' => false,
                'error'   => 'cannot delete the model',
            ], 500);
        }

        return new JsonResponse([
            'success'      => true,
            'duration_sec' => \microtime(true) - $started_at,
            'memory_bytes' => \memory_get_usage() - $memory_bytes,
        ]);
    }

    /**
     * Test queue job dispatching and processing.
     *
     * @param Dispatcher      $dispatcher
     * @param CacheRepository $cache
     *
     * @return JsonResponse
     */
    public function queue(Dispatcher $dispatcher, CacheRepository $cache): JsonResponse
    {
        Log::info('Processing request to test the queue', [
            'steve' => 'oliver',
        ]);

        $started_at    = \microtime(true);
        $memory_bytes  = \memory_get_usage();
        $random_string = Str::random();

        $dispatcher->dispatch(new \App\Jobs\TestJob($random_string));

        for ($i = 0; $i < 10 * 10; $i++) { // 10 seconds
            if ($cache->get($random_string) === true) {
                return new JsonResponse([
                    'success'      => true,
                    'duration_sec' => \microtime(true) - $started_at,
                    'memory_bytes' => \memory_get_usage() - $memory_bytes,
                ]);
            }

            \usleep(100_000);
        }

        return new JsonResponse([
            'success' => false,
            'error'   => 'job processing timeout exceeded',
        ], 500);
    }

    public function trace(Request $request): JsonResponse
    {
        $rootSpan = Trace::getCurrentSpan();

        Log::info('Tracing Laravel request');

        $span = Trace::startSpan('First thing', $rootSpan->getContext());
        sleep(1);
        Log::debug("Slept for 1 second");
        $span->annotate('Annotating something in the middle of the span');
        sleep(1);
        Log::debug("Slept again for 1 second");
        $span->finish();

        $span = Trace::startSpan('Second thing', $rootSpan->getContext());
        sleep(2);
        Log::debug("Slept for 2 seconds");
        $span->annotate('Annotating something in the middle of the span');
        sleep(2);
        Log::debug("Slept again for 2 seconds");
        $span->finish();

        $span = Trace::startSpan('Third thing', $rootSpan->getContext());
        sleep(3);
        Log::debug("Slept for 3 seconds");
        $span->annotate('Annotating something in the middle of the span');
        sleep(3);
        Log::debug("Slept again for 3 seconds");
        $span->finish();

        return new JsonResponse([
            'status' => 'success',
        ]);
    }

    /**
     * Dump incoming request data.
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function dump(Request $request): JsonResponse
    {
        Log::debug('Processing request to dump Laravel context');
        $memory_bytes = \memory_get_usage();

        $response_data = [
            'success' => true,
            'request' => [
                'url'         => [
                    'string'   => $request->url(),
                    'full'     => $request->fullUrl(),
                    'root'     => $request->root(),
                    'path'     => $request->path(),
                    'segments' => $request->segments(),
                ],
                'method'      => $request->method(),
                'schema'      => $request->getScheme(),
                'ajax'        => $request->ajax(),
                'pjax'        => $request->pjax(),
                'prefetch'    => $request->prefetch(),
                'secure'      => $request->secure(),
                'ip'          => $request->ip(),
                'ips'         => $request->ips(),
                'user_agent'  => $request->userAgent(),
                'fingerprint' => $request->fingerprint(),
                'files'       => $request->allFiles(),
                'headers'     => $request->headers->all(),
                'content'     => $request->getContent(),
            ],
            'session' => [
                'name'         => $request->session()->getName(),
                'id'           => $request->session()->getId(),
                'previous_url' => $request->session()->previousUrl(),
                'token'        => $request->session()->token(),
            ],
        ];

        $response_data['memory_bytes'] = \memory_get_usage() - $memory_bytes;

        return new JsonResponse($response_data);
    }

    /**
     * Generates application URLs.
     *
     * @param UrlGenerator $url
     *
     * @return JsonResponse
     */
    public function url(UrlGenerator $url): JsonResponse
    {
        Log::debug('Processing request to show URL and routing information.', [
            'url_generator' => $url->to('/'),
        ]);
        return new JsonResponse([
            'success'  => true,
            'foo' => 'bar',
            'base_url' => [
                'url_generator' => $url->to('/'),
                'facade'        => \Illuminate\Support\Facades\URL::to('/'),
                'helper'        => url('/'),
            ],
        ]);
    }

    /**
     * Test file uploading and saving using filesystem.
     *
     * @param Request    $request
     * @param Filesystem $fs
     *
     * @return JsonResponse
     */
    public function upload(Request $request, Filesystem $fs): JsonResponse
    {
        $started_at   = \microtime(true);
        $memory_bytes = \memory_get_usage();

        $file = $request->file('data');

        if ($file instanceof UploadedFile) {
            $file->move(storage_path('app'), $file_name = Str::random(6) . '_' . $file->getClientOriginalName());

            return new JsonResponse([
                'success'      => true,
                'content_size' => $fs->size($file_name),
                'duration_sec' => \microtime(true) - $started_at,
                'memory_bytes' => \memory_get_usage() - $memory_bytes,
            ]);
        }

        return new JsonResponse([
            'success' => false,
            'error'   => 'file was not submitted (use key "data" for file content)',
        ], 400);
    }
}
