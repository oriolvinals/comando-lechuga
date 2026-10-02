<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Saloon\Http\Response;

/**
 * An Instagram API failure, with the Graph error code (190 = the access token expired or was revoked).
 */
class InstagramApiException extends RuntimeException
{
    public const int EXPIRED_TOKEN_CODE = 190;

    public function __construct(string $message, public readonly int $errorCode = 0)
    {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response, string $action): self
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];
        $code = (int) ($error['code'] ?? 0);
        $detail = (string) ($error['error_user_msg'] ?? $error['message'] ?? $response->body());

        if ($code === self::EXPIRED_TOKEN_CODE) {
            return new self(
                "Instagram access token expired or invalid (error 190) while trying to {$action}: {$detail}. "
                .'Generate a new long-lived token, set INSTAGRAM_ACCESS_TOKEN and run instagram:refresh-token.',
                $code,
            );
        }

        return new self("Instagram could not {$action} (HTTP {$response->status()}, error {$code}): {$detail}", $code);
    }

    public function isExpiredToken(): bool
    {
        return $this->errorCode === self::EXPIRED_TOKEN_CODE;
    }
}
