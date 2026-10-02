<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PublishedStoryType;
use Carbon\CarbonImmutable;
use Database\Factories\PublishedStoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One Instagram story published by the app: part `part` of `parts` of a batch of one (Madrid) day. A batch is one
 * publication (its activities are marked shared once every part is out); `activity_ids` lists the whole batch's
 * activities, so an interrupted batch resumes with the same content. A batch without activities is the
 * «nobody signed today» story.
 *
 * @property-read int $id
 * @property-read CarbonImmutable $date
 * @property-read PublishedStoryType $type
 * @property-read int $batch
 * @property-read int $part
 * @property-read int $parts
 * @property-read int $signings_count
 * @property-read list<int> $activity_ids
 * @property-read string $media_id
 * @property-read CarbonImmutable $published_at
 */
#[UseFactory(PublishedStoryFactory::class)]
#[Table(name: 'published_stories', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['date', 'type', 'batch', 'part', 'parts', 'signings_count', 'activity_ids', 'media_id', 'published_at'])]
class PublishedStory extends Model
{
    /** @use HasFactory<PublishedStoryFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'signings_count' => 0,
        'media_id' => '',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'date' => 'immutable_date',
            'type' => PublishedStoryType::class,
            'batch' => 'int',
            'part' => 'int',
            'parts' => 'int',
            'signings_count' => 'int',
            'activity_ids' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }
}
