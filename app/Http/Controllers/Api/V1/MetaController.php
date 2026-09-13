<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The API answers requests and nothing else — the endpoint reference lives
 * behind login in the panel, not on the open internet.
 */
class MetaController extends Controller
{
    use RespondsWithJson;

    /**
     * Anything under /api that matched no route. Without this Laravel renders
     * an HTML error page, which breaks a bot's JSON parser on a simple typo.
     */
    public function fallback(): JsonResponse
    {
        return $this->fail('endpoint_not_found', __('api.endpoint_not_found'), 404);
    }
}
