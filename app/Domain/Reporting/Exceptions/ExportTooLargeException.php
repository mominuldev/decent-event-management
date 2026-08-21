<?php

namespace App\Domain\Reporting\Exceptions;

use App\Domain\Registration\Exceptions\RegistrationRejectedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The filter set selects more rows than the synchronous export path is
 * willing to build in one request.
 *
 * Caller error, not a server fault, so it renders as a 422 in the uniform
 * envelope rather than a 500 — the same reasoning as
 * {@see RegistrationRejectedException}.
 * Refusing up front is the honest answer: the alternative is a request that
 * runs for minutes and then dies to a timeout or the memory limit, leaving
 * the operator with a failed download and no idea why.
 *
 * The message names both numbers on purpose, so "narrow your filters" is
 * actionable rather than a shrug.
 */
class ExportTooLargeException extends RuntimeException
{
    public function __construct(
        public readonly int $rowCount,
        public readonly int $maxRows,
        public readonly string $format,
    ) {
        parent::__construct(sprintf(
            'This export would contain %s rows, which is more than the %s the %s format can build in one request. Narrow the filters and try again.',
            number_format($rowCount),
            number_format($maxRows),
            strtoupper($format),
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => 'export_too_large',
            'message' => $this->getMessage(),
            'request_id' => $request->header('X-Request-Id'),
        ], 422);
    }
}
