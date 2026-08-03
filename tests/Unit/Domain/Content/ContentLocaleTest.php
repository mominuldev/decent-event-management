<?php

namespace Tests\Unit\Domain\Content;

use App\Domain\Content\Support\ContentLocale;
use Illuminate\Http\Request;
use Tests\TestCase;

class ContentLocaleTest extends TestCase
{
    public function test_an_empty_or_unsupported_header_resolves_to_english(): void
    {
        $this->assertSame('en', ContentLocale::fromAcceptLanguage(''));
        $this->assertSame('en', ContentLocale::fromAcceptLanguage('fr-FR,de;q=0.8'));
    }

    public function test_region_subtags_resolve_to_their_primary_language(): void
    {
        $this->assertSame('bn', ContentLocale::fromAcceptLanguage('bn-BD'));
        $this->assertSame('en', ContentLocale::fromAcceptLanguage('en-GB'));
    }

    public function test_quality_weights_decide_between_supported_languages(): void
    {
        $this->assertSame('bn', ContentLocale::fromAcceptLanguage('en;q=0.5,bn;q=0.9'));
        $this->assertSame('en', ContentLocale::fromAcceptLanguage('bn;q=0.4,en;q=0.7'));
    }

    public function test_unsupported_languages_are_skipped_rather_than_winning_on_weight(): void
    {
        // French has the highest weight but is not a content language; the
        // best *supported* option must still win.
        $this->assertSame('bn', ContentLocale::fromAcceptLanguage('fr;q=1.0,bn;q=0.6,en;q=0.5'));
    }

    public function test_an_explicit_locale_query_parameter_overrides_the_header(): void
    {
        $request = Request::create('/', 'GET', ['locale' => 'en'], server: ['HTTP_ACCEPT_LANGUAGE' => 'bn']);

        $this->assertSame('en', ContentLocale::resolve($request));
    }

    public function test_an_unsupported_locale_parameter_is_ignored(): void
    {
        $request = Request::create('/', 'GET', ['locale' => 'fr'], server: ['HTTP_ACCEPT_LANGUAGE' => 'bn']);

        $this->assertSame('bn', ContentLocale::resolve($request));
    }

    public function test_pick_falls_back_to_english_when_bangla_is_missing_or_blank(): void
    {
        $this->assertSame('English', ContentLocale::pick('bn', 'English', null));
        $this->assertSame('English', ContentLocale::pick('bn', 'English', '   '));
        $this->assertSame('বাংলা', ContentLocale::pick('bn', 'English', 'বাংলা'));
        $this->assertSame('English', ContentLocale::pick('en', 'English', 'বাংলা'));
    }

    public function test_pick_array_merges_per_key_so_a_half_translated_block_still_renders(): void
    {
        $result = ContentLocale::pickArray(
            'bn',
            ['heading' => 'Our history', 'body' => 'A century.'],
            ['heading' => 'আমাদের ইতিহাস'],
        );

        $this->assertSame(['heading' => 'আমাদের ইতিহাস', 'body' => 'A century.'], $result);
    }

    public function test_pick_array_ignores_blank_translations(): void
    {
        $result = ContentLocale::pickArray(
            'bn',
            ['heading' => 'Our history', 'body' => 'A century.'],
            ['heading' => '', 'body' => null],
        );

        $this->assertSame(['heading' => 'Our history', 'body' => 'A century.'], $result);
    }

    public function test_english_requests_never_read_the_bangla_column(): void
    {
        $result = ContentLocale::pickArray('en', ['heading' => 'Our history'], ['heading' => 'আমাদের ইতিহাস']);

        $this->assertSame(['heading' => 'Our history'], $result);
    }
}
