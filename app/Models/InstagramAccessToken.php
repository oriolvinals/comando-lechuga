<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\InstagramAccessTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A refreshed long-lived Instagram Login access token (encrypted at rest). The newest row is the one in use; the
 * INSTAGRAM_ACCESS_TOKEN env value only seeds it until the first refresh. PRIVATE: never exposed.
 *
 * @property-read int $id
 * @property-read string $access_token
 * @property-read CarbonImmutable|null $expires_at
 * @property-read CarbonImmutable $refreshed_at
 */
#[UseFactory(InstagramAccessTokenFactory::class)]
#[Table(name: 'instagram_access_tokens', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['access_token', 'expires_at', 'refreshed_at'])]
#[Hidden(['access_token'])]
class InstagramAccessToken extends Model
{
    /** @use HasFactory<InstagramAccessTokenFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'access_token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'refreshed_at' => 'immutable_datetime',
        ];
    }
}
