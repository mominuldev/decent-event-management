<?php

namespace Tests\Unit\Domain\Notification;

use App\Domain\Notification\Support\Msisdn;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Number formatting for the SMS gateway. The rule that carries the risk
 * is the last one: an international number must survive untouched, or an
 * overseas alumnus's `+44…` becomes a Bangladeshi MSISDN that either
 * fails to deliver or — worse — reaches a stranger.
 */
class MsisdnTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function numbers(): array
    {
        return [
            'national form gains the country code' => ['01711223344', '8801711223344'],
            'already international, plus stripped' => ['+8801711223344', '8801711223344'],
            'already international, bare' => ['8801711223344', '8801711223344'],
            'spaces and dashes are noise' => ['+880 1711-223344', '8801711223344'],
            '00 is the other spelling of +' => ['008801711223344', '8801711223344'],
            'trunk prefix omitted' => ['1711223344', '8801711223344'],
            'a foreign number is left alone' => ['+447700900123', '447700900123'],
            'a foreign number keeps its own country code' => ['+971501234567', '971501234567'],
            'too short to dial' => ['12345', null],
            'no digits at all' => ['not a number', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_it_formats_a_number_for_the_gateway(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Msisdn::format($input));
    }

    public function test_a_foreign_number_is_never_rewritten_as_bangladeshi(): void
    {
        // The failure this guards: a UK number reduced to digits starts
        // `44`, which is neither `01…` nor `880…`. If the national-form
        // branch were widened to catch "anything 10-11 digits", this would
        // silently become `88044…`.
        $this->assertSame('447700900123', Msisdn::format('+44 7700 900123'));
        $this->assertStringStartsNotWith('880', (string) Msisdn::format('+44 7700 900123'));
    }
}
