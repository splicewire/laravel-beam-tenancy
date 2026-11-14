<?php

namespace Splicewire\Beam\Tenancy\Localization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Splicewire\Beam\Localization\CodedError;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class TenancyHttpException extends HttpException
{
    private ?CodedError $codedError = null;

    private array $parameters = [];

    public static function for(int $status, TenancyError $error, array $params = [], ?Throwable $previous = null, array $headers = []): self
    {
        $replace = [];
        foreach ($params as $key => $value) {
            $replace[':'.$key] = (string) $value;
        }
        $exception = new self($status, strtr($error->defaultMessage(), $replace), $previous, $headers);
        $exception->codedError = $error;
        $exception->parameters = $params;

        return $exception;
    }

    public function explicitCodedError(): ?CodedError
    {
        return $this->codedError;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() || $this->codedError === null) {
            return null;
        }

        return new JsonResponse((new TenancyErrorData($this->codedError->message($this->parameters), $this->codedError->code()))->toArray(), $this->getStatusCode(), $this->getHeaders(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
