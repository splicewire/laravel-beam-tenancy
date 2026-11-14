<?php

namespace Splicewire\Beam\Tenancy\Localization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Splicewire\Beam\Tenancy\Exceptions\SoleTenantNotIdentified;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class TenancyResponses
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }
        if ($exception instanceof TenancyHttpException) {
            return $exception->render($request);
        }

        // A host can prepare the original identification failure as a concrete HTTP503.
        // Only its explicit factory identity is recognized; never guess from diagnostic text.
        $source = $exception instanceof SoleTenantNotIdentified
            ? $exception
            : ($exception instanceof HttpException && $exception->getStatusCode() === 503 ? $exception->getPrevious() : null);
        if (! $source instanceof SoleTenantNotIdentified || ($error = $source->explicitCodedError()) === null) {
            return null;
        }

        return new JsonResponse((new TenancyErrorData($error->message(), $error->code()))->toArray(), $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503, $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
