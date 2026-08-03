<?php

namespace Tests\Unit\Domain\Notification;

use App\Domain\Notification\Support\SmsSegmentCalculator;
use Tests\TestCase;

class SmsSegmentCalculatorTest extends TestCase
{
    public function test_gsm7_text_within_one_segment(): void
    {
        $this->assertTrue(SmsSegmentCalculator::isGsm7('Your ticket is ready.'));
        $this->assertSame(1, SmsSegmentCalculator::segmentCount(str_repeat('a', 160)));
    }

    public function test_gsm7_text_over_one_segment(): void
    {
        $this->assertSame(2, SmsSegmentCalculator::segmentCount(str_repeat('a', 161)));
        $this->assertSame(2, SmsSegmentCalculator::segmentCount(str_repeat('a', 320)));
        $this->assertSame(3, SmsSegmentCalculator::segmentCount(str_repeat('a', 321)));
    }

    public function test_bangla_text_is_not_gsm7_and_costs_more_per_segment(): void
    {
        $bangla = 'আপনার টিকিট প্রস্তুত';

        $this->assertFalse(SmsSegmentCalculator::isGsm7($bangla));
        $this->assertSame(1, SmsSegmentCalculator::segmentCount(str_repeat('ক', 70)));
        $this->assertSame(2, SmsSegmentCalculator::segmentCount(str_repeat('ক', 71)));
    }

    public function test_single_non_gsm7_character_forces_the_whole_message_to_unicode_pricing(): void
    {
        // One Bangla character mixed into an otherwise-Latin message still
        // costs the 70-char Unicode rate for the entire message, matching
        // how carriers actually encode SMS (docs/01 §1.6).
        $mixed = str_repeat('a', 69).'ক';

        $this->assertFalse(SmsSegmentCalculator::isGsm7($mixed));
        $this->assertSame(1, SmsSegmentCalculator::segmentCount($mixed));
        $this->assertSame(2, SmsSegmentCalculator::segmentCount($mixed.'a'));
    }

    public function test_empty_string_costs_one_segment(): void
    {
        $this->assertSame(1, SmsSegmentCalculator::segmentCount(''));
    }
}
