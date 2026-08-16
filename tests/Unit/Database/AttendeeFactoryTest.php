<?php

namespace Tests\Unit\Database;

use App\Domain\Registration\Models\Attendee;
use Tests\TestCase;

/**
 * The batch-year rule is a property of the participant type, and a fixture
 * that breaks it is one no registration could have produced —
 * StoreRegistrationRequest requires a batch year from a current or former
 * student. This regressed silently once already: DummyDataSeeder overrides
 * `participant_type` after the factory has already derived the batch year
 * from a different one, which left real seeded former students with none.
 */
class AttendeeFactoryTest extends TestCase
{
    public function test_a_student_always_gets_a_batch_year_even_when_the_type_is_overridden(): void
    {
        foreach (['current_student', 'former_student'] as $type) {
            for ($i = 0; $i < 25; $i++) {
                $attendee = Attendee::factory()->make(['participant_type' => $type]);

                $this->assertNotNull(
                    $attendee->ssc_batch_year,
                    "a {$type} fixture must carry the batch year the public form requires",
                );
            }
        }
    }

    public function test_a_non_student_never_carries_a_batch_year_or_a_class(): void
    {
        foreach (['teacher', 'staff', 'guardian', 'guest', 'sponsor', 'other'] as $type) {
            for ($i = 0; $i < 25; $i++) {
                $attendee = Attendee::factory()->make(['participant_type' => $type]);

                // A stale year left behind by an overridden type would make
                // the batch-year reporting segment lie.
                $this->assertNull($attendee->ssc_batch_year, "a {$type} has no SSC batch here");
                $this->assertNull($attendee->current_class, "a {$type} is not sitting in a class");
            }
        }
    }

    public function test_only_a_current_student_carries_a_class(): void
    {
        $current = Attendee::factory()->make(['participant_type' => 'current_student']);
        $former = Attendee::factory()->make(['participant_type' => 'former_student']);

        $this->assertNotNull($current->current_class);
        $this->assertNull($former->current_class, 'a former student has left');
    }

    public function test_an_explicitly_supplied_batch_year_is_never_overwritten(): void
    {
        $attendee = Attendee::factory()->make([
            'participant_type' => 'former_student',
            'ssc_batch_year' => 1999,
        ]);

        $this->assertSame(1999, $attendee->ssc_batch_year);
    }
}
