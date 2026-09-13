<?php

namespace Database\Factories;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Support\NationalId;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendee>
 */
class AttendeeFactory extends Factory
{
    protected $model = Attendee::class;

    /**
     * Deliberately includes conjuncts (ক্ষ, প্র, দ্দ) and pre-base vowel
     * signs — the exact shapes the ticket PDF's text layer gets wrong
     * (see GenerateTicketPdf's docblock), so a fixture is never accidentally
     * conjunct-free and quietly passing.
     *
     * @var list<string>
     */
    private const BANGLA_NAMES = [
        'রহিম উদ্দিন',
        'সালমা খাতুন',
        'প্রদীপ কুমার দাস',
        'ফারহানা ইসলাম',
        'মোঃ কামরুল হাসান',
        'সুমাইয়া আক্তার',
        'অক্ষয় চন্দ্র রায়',
        'নাসরিন সুলতানা',
    ];

    /**
     * Keeps the batch-year and class columns consistent with whatever
     * `participant_type` the row *ends up* with.
     *
     * `definition()` derives both from the type it picked itself, which is
     * wrong the moment a caller overrides that type — and DummyDataSeeder
     * does exactly that, replacing it with one the chosen ticket type allows.
     * The overridden row then kept a batch year derived from the discarded
     * type: seeded former students with no batch year (9 of 17 in a real dev
     * database) and sponsors carrying one. The public form requires a batch
     * year from a current or former student
     * (`StoreRegistrationRequest::rules()`), so a seeded student without one
     * is a fixture no registration could have produced.
     *
     * Runs `afterMaking` rather than in `definition()` because that is the
     * only hook that sees the caller's overrides merged in.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Attendee $attendee): void {
            $isStudent = in_array($attendee->participant_type, ['current_student', 'former_student'], true);

            $attendee->fill([
                // Filled only when absent, so an explicit year from a test or
                // seeder survives. Cleared for everyone else: a teacher or
                // sponsor has no SSC batch, and a stale one left behind by an
                // overridden type makes the reporting segment lie.
                'ssc_batch_year' => $isStudent
                    ? $attendee->ssc_batch_year ?? fake()->numberBetween(1971, 2024)
                    : null,
                'current_class' => $attendee->participant_type === 'current_student'
                    ? $attendee->current_class ?? fake()->randomElement(['9', '10'])
                    : null,
            ]);
        });
    }

    /**
     * Real district / upazila / post-office triples, so seeded addresses are
     * internally consistent.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const ADDRESSES = [
        ['Dhaka', 'Savar', 'Savar'],
        ['Dhaka', 'Dhamrai', 'Dhamrai'],
        ['Gazipur', 'Kaliakair', 'Kaliakair'],
        ['Chattogram', 'Hathazari', 'Hathazari'],
        ['Chattogram', 'Patiya', 'Patiya'],
        ['Sylhet', 'Beanibazar', 'Beanibazar'],
        ['Rajshahi', 'Bagmara', 'Bhabaniganj'],
        ['Khulna', 'Dumuria', 'Dumuria'],
        ['Barishal', 'Bakerganj', 'Bakerganj'],
        ['Cumilla', 'Laksam', 'Laksam'],
        ['Mymensingh', 'Trishal', 'Trishal'],
        ['Rangpur', 'Mithapukur', 'Mithapukur'],
    ];

    /**
     * @return array{address_district: string, upazila: string, post_office: string}
     */
    private static function address(): array
    {
        /** @var array{0: string, 1: string, 2: string} $picked */
        $picked = fake()->randomElement(self::ADDRESSES);

        return [
            'address_district' => $picked[0],
            'upazila' => $picked[1],
            'post_office' => $picked[2],
        ];
    }

    public function definition(): array
    {
        $participantType = fake()->randomElement([
            'current_student', 'former_student', 'teacher', 'staff', 'guest', 'sponsor',
        ]);

        $needsBatchYear = in_array($participantType, ['current_student', 'former_student'], true);

        return [
            'full_name' => fake()->name(),
            // Faker ships no bn_BD name provider, so this draws from a small
            // fixed pool rather than transliterating — a fabricated
            // transliteration would read as nonsense to anyone actually
            // checking a Bangla name renders correctly on a ticket.
            'full_name_bn' => fake()->randomElement(self::BANGLA_NAMES),
            // Always set, unlike the other optional biographical fields: the
            // public registration form requires it, so a factory attendee
            // that never has one would not resemble a real registrant.
            'father_name' => fake()->name('male'),
            'mobile' => '+8801'.fake()->numerify('#########'),
            'whatsapp_number' => fake()->boolean(30) ? '+8801'.fake()->numerify('#########') : null,
            // `unique()`, because `attendees.email` is a unique column —
            // plain safeEmail() draws from a small pool and collides well
            // before a factory run of any size finishes.
            'email' => fake()->boolean(70) ? fake()->unique()->safeEmail() : null,
            'gender' => fake()->randomElement(['male', 'female', 'other', 'prefer_not_to_say']),
            // Always set, like the other fields the public form requires —
            // a coin flip here would leave a large share of factory
            // attendees looking like pre-2026-09-13 legacy rows, which is
            // the one shape the form can no longer produce.
            'date_of_birth' => fake()->dateTimeBetween('-70 years', '-15 years'),
            // A coin flip *is* right for these two: both are optional on the
            // form, so a fixture set where every attendee has an NID would
            // not exercise the far more common case of a blank one.
            'nid_number' => fake()->boolean(55)
                ? fake()->numerify(str_repeat('#', fake()->randomElement(NationalId::LENGTHS)))
                : null,
            'blood_group' => fake()->boolean(65) ? fake()->randomElement(Attendee::BLOOD_GROUPS) : null,
            'occupation' => fake()->jobTitle(),
            'participant_type' => $participantType,
            // Derived from the type picked directly above; `configure()` is
            // what reconciles these two when a caller overrides that type.
            'ssc_batch_year' => $needsBatchYear ? fake()->numberBetween(1971, 2024) : null,
            'current_class' => $participantType === 'current_student' ? fake()->randomElement(['9', '10']) : null,
            'tshirt_required' => fake()->boolean(70),
            'tshirt_size' => fake()->randomElement(['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL']),
            // Drawn as a whole triple, not three independent picks: a post
            // office in Savar under Sylhet district is not an address, and a
            // fixture that reads as nonsense is one nobody trusts when a
            // directory page looks wrong.
            ...self::address(),
            'current_address' => fake()->buildingNumber().', '.fake()->streetName().', '.fake()->city(),
            'country' => 'BD',
            'is_verified' => fake()->boolean(40),
        ];
    }
}
