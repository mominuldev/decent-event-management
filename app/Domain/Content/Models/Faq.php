<?php

namespace App\Domain\Content\Models;

use App\Domain\Content\Support\IsPublishableContent;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\FaqFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    /** @use HasFactory<FaqFactory> */
    use HasFactory, HasUlid, IsPublishableContent;

    protected $table = 'faqs';

    protected $fillable = [
        'question',
        'question_bn',
        'answer',
        'answer_bn',
        'category',
        'category_bn',
        'position',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }
}
