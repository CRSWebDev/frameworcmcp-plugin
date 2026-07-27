<?php namespace CRSCompany\FrameworCMcp\Classes;

use CRSCompany\FrameworCMcp\Models\McpSetting;
use Illuminate\Http\Request;

/**
 * TokenGuard checks the bearer token against the configured setting.
 */
class TokenGuard
{
    /**
     * check throws when the request is not authorised.
     *
     * An unset token means the API is switched off, which is reported as 503
     * rather than 401 — a blank setting must never read as "no auth required".
     */
    public static function check(Request $request): void
    {
        $expected = McpSetting::getApiToken();

        if ($expected === null) {
            throw new ApiException(
                'The FrameworC MCP API is disabled. Set an API token in Settings > FrameworC > MCP API.',
                503
            );
        }

        $provided = static::extractToken($request);

        if ($provided === null) {
            throw new ApiException('Missing bearer token.', 401);
        }

        if (!hash_equals($expected, $provided)) {
            throw new ApiException('Invalid bearer token.', 401);
        }
    }

    /**
     * extractToken pulls the token out of the Authorization header.
     */
    protected static function extractToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }
}
