import { Field, FormSection, Input, Select, Textarea } from '@/components/ui';
import { BLOOD_GROUPS, CURRENT_CLASSES, isCatalogueClass, type Attendee } from '@/features/attendees/types';
import { BATCH_YEAR_PARTICIPANT_TYPES, PARTICIPANT_TYPES } from './types';

/**
 * The attendee half of a registration form, shared by the counter page
 * (creating) and the registration page (correcting). One component rather
 * than two copies because the field set is the contract with the server —
 * a field added to one form and not the other is a record the desk can
 * take and nobody can fix, or the other way round.
 */

export function titleCase(s: string) {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Ceiling for the date-of-birth picker, matching the API's `before:today`.
 * Local time, not UTC: for a reader in Dhaka the UTC date is yesterday until
 * 6am, and the input's own bound must not disagree with the server's.
 */
const TODAY = (() => {
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
})();

export interface AttendeeFormState {
    full_name: string;
    father_name: string;
    mobile: string;
    email: string;
    gender: 'male' | 'female';
    date_of_birth: string;
    nid_number: string;
    blood_group: string;
    occupation: string;
    designation: string;
    organization: string;
    current_address: string;
    post_office: string;
    upazila: string;
    address_district: string;
    participant_type: string;
    ssc_batch_year: string;
    current_class: string;
    current_section: string;
    current_roll: string;
}

export const EMPTY_ATTENDEE_FORM: AttendeeFormState = {
    full_name: '',
    father_name: '',
    mobile: '',
    email: '',
    gender: 'male',
    date_of_birth: '',
    nid_number: '',
    blood_group: '',
    occupation: '',
    designation: '',
    organization: '',
    current_address: '',
    post_office: '',
    upazila: '',
    address_district: '',
    participant_type: 'former_student',
    ssc_batch_year: '',
    current_class: '',
    current_section: '',
    current_roll: '',
};

export function attendeeFormFrom(a: Attendee): AttendeeFormState {
    return {
        full_name: a.full_name ?? '',
        father_name: a.father_name ?? '',
        mobile: a.mobile ?? '',
        email: a.email ?? '',
        gender: a.gender === 'female' ? 'female' : 'male',
        date_of_birth: a.date_of_birth ? a.date_of_birth.slice(0, 10) : '',
        nid_number: a.nid_number ?? '',
        blood_group: a.blood_group ?? '',
        occupation: a.occupation ?? '',
        designation: a.designation ?? '',
        organization: a.organization ?? '',
        current_address: a.current_address ?? '',
        post_office: a.post_office ?? '',
        upazila: a.upazila ?? '',
        address_district: a.address_district ?? '',
        participant_type: a.participant_type,
        ssc_batch_year: a.ssc_batch_year ? String(a.ssc_batch_year) : '',
        current_class: a.current_class ?? '',
        current_section: a.current_section ?? '',
        current_roll: a.current_roll ?? '',
    };
}

export function isCurrentStudent(form: AttendeeFormState): boolean {
    return form.participant_type === 'current_student';
}

export function needsBatchYear(form: AttendeeFormState): boolean {
    return BATCH_YEAR_PARTICIPANT_TYPES.includes(form.participant_type);
}

export function AttendeeFields({
    form,
    set,
    err,
    mode,
    disabled = false,
    allowedParticipantTypes = PARTICIPANT_TYPES,
    gender,
}: {
    form: AttendeeFormState;
    set: <K extends keyof AttendeeFormState>(key: K, value: AttendeeFormState[K]) => void;
    err: (field: string) => string | undefined;
    /**
     * `create` asks for gender, which the counter request requires and the
     * admin edit request does not accept — on `edit` it is shown as text.
     */
    mode: 'create' | 'edit';
    disabled?: boolean;
    /** The ticket type's audience; everyone when it sets none. */
    allowedParticipantTypes?: readonly string[];
    /** The recorded gender, for the read-only `edit` rendering. */
    gender?: string | null;
}) {
    const student = isCurrentStudent(form);

    return (
        <>
            <FormSection title="Attendee">
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field id="full-name" label="Full name" error={err('full_name')}>
                        <Input
                            id="full-name"
                            value={form.full_name}
                            disabled={disabled}
                            aria-invalid={Boolean(err('full_name'))}
                            onChange={(e) => set('full_name', e.target.value)}
                        />
                    </Field>
                    <Field id="father-name" label="Father's name" error={err('father_name')}>
                        <Input
                            id="father-name"
                            value={form.father_name}
                            disabled={disabled}
                            aria-invalid={Boolean(err('father_name'))}
                            onChange={(e) => set('father_name', e.target.value)}
                        />
                    </Field>
                    <Field
                        id="mobile"
                        label="Mobile"
                        error={err('mobile')}
                        hint="Identifies the attendee, and is how they sign in."
                    >
                        <Input
                            id="mobile"
                            value={form.mobile}
                            disabled={disabled}
                            aria-invalid={Boolean(err('mobile'))}
                            onChange={(e) => set('mobile', e.target.value)}
                            placeholder="01712345678"
                        />
                    </Field>
                    <Field id="email" label="Email" optional error={err('email')} hint="Where the ticket is sent.">
                        <Input
                            id="email"
                            type="email"
                            value={form.email}
                            disabled={disabled}
                            aria-invalid={Boolean(err('email'))}
                            onChange={(e) => set('email', e.target.value)}
                        />
                    </Field>
                    {mode === 'create' ? (
                        <Field id="gender" label="Gender" error={err('gender')}>
                            <Select
                                id="gender"
                                value={form.gender}
                                disabled={disabled}
                                onChange={(e) => set('gender', e.target.value as AttendeeFormState['gender'])}
                            >
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </Select>
                        </Field>
                    ) : (
                        <Field id="gender" label="Gender" hint="Recorded at registration; not editable here.">
                            <Input id="gender" value={gender ? titleCase(gender) : '—'} disabled readOnly />
                        </Field>
                    )}
                    <Field id="participant-type" label="Participant type" error={err('participant_type')}>
                        <Select
                            id="participant-type"
                            value={form.participant_type}
                            disabled={disabled}
                            aria-invalid={Boolean(err('participant_type'))}
                            onChange={(e) => set('participant_type', e.target.value)}
                        >
                            {allowedParticipantTypes.map((p) => (
                                <option key={p} value={p}>
                                    {titleCase(p)}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field id="occupation" label="Occupation" error={err('occupation')}>
                        <Input
                            id="occupation"
                            value={form.occupation}
                            disabled={disabled}
                            aria-invalid={Boolean(err('occupation'))}
                            onChange={(e) => set('occupation', e.target.value)}
                        />
                    </Field>
                    {needsBatchYear(form) && (
                        <Field id="batch-year" label="SSC batch year" error={err('ssc_batch_year')}>
                            <Input
                                id="batch-year"
                                type="number"
                                value={form.ssc_batch_year}
                                disabled={disabled}
                                aria-invalid={Boolean(err('ssc_batch_year'))}
                                onChange={(e) => set('ssc_batch_year', e.target.value)}
                            />
                        </Field>
                    )}
                    <Field id="designation" label="Designation" optional error={err('designation')}>
                        <Input
                            id="designation"
                            value={form.designation}
                            disabled={disabled}
                            aria-invalid={Boolean(err('designation'))}
                            onChange={(e) => set('designation', e.target.value)}
                        />
                    </Field>
                    <Field id="organization" label="Organization" optional error={err('organization')}>
                        <Input
                            id="organization"
                            value={form.organization}
                            disabled={disabled}
                            aria-invalid={Boolean(err('organization'))}
                            onChange={(e) => set('organization', e.target.value)}
                        />
                    </Field>
                </div>
            </FormSection>

            {/* Only a current student is placed by class, section and roll —
                the way a former student is by their batch year. */}
            {student && (
                <FormSection title="Current student" description="Their class, section and roll number as the school records them.">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Field id="current-class" label="Class" error={err('current_class')}>
                            <Select
                                id="current-class"
                                value={form.current_class}
                                disabled={disabled}
                                aria-invalid={Boolean(err('current_class'))}
                                onChange={(e) => set('current_class', e.target.value)}
                            >
                                <option value="">Pick a class</option>
                                {form.current_class && !isCatalogueClass(form.current_class) && (
                                    <option value={form.current_class}>{form.current_class} (as recorded)</option>
                                )}
                                {CURRENT_CLASSES.map((c) => (
                                    <option key={c.value} value={c.value}>
                                        {c.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field id="current-section" label="Section" error={err('current_section')}>
                            <Input
                                id="current-section"
                                value={form.current_section}
                                disabled={disabled}
                                aria-invalid={Boolean(err('current_section'))}
                                onChange={(e) => set('current_section', e.target.value)}
                                placeholder="A"
                            />
                        </Field>
                        {/* Text, not a number: the school writes "07", and a
                            numeric input would hand back 7. */}
                        <Field id="current-roll" label="Roll" error={err('current_roll')}>
                            <Input
                                id="current-roll"
                                inputMode="numeric"
                                value={form.current_roll}
                                disabled={disabled}
                                aria-invalid={Boolean(err('current_roll'))}
                                onChange={(e) => set('current_roll', e.target.value)}
                                placeholder="07"
                            />
                        </Field>
                    </div>
                </FormSection>
            )}

            <FormSection title="Address">
                {/* Textarea, not an input: a Bangladeshi address runs to two
                    or three lines and a box that scrolls sideways hides what
                    was already typed. */}
                <Field id="current-address" label="Current address" error={err('current_address')}>
                    <Textarea
                        id="current-address"
                        rows={2}
                        value={form.current_address}
                        disabled={disabled}
                        aria-invalid={Boolean(err('current_address'))}
                        onChange={(e) => set('current_address', e.target.value)}
                    />
                </Field>
                {/* Free text, not pickers — upazilas and post offices are
                    renamed and reassigned by notification, and a stale list
                    would refuse a place that exists. */}
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field id="post-office" label="Post office" error={err('post_office')}>
                        <Input
                            id="post-office"
                            value={form.post_office}
                            disabled={disabled}
                            aria-invalid={Boolean(err('post_office'))}
                            onChange={(e) => set('post_office', e.target.value)}
                        />
                    </Field>
                    <Field id="upazila" label="Upazila" error={err('upazila')}>
                        <Input
                            id="upazila"
                            value={form.upazila}
                            disabled={disabled}
                            aria-invalid={Boolean(err('upazila'))}
                            onChange={(e) => set('upazila', e.target.value)}
                        />
                    </Field>
                    <Field id="address-district" label="District" error={err('address_district')}>
                        <Input
                            id="address-district"
                            value={form.address_district}
                            disabled={disabled}
                            aria-invalid={Boolean(err('address_district'))}
                            onChange={(e) => set('address_district', e.target.value)}
                        />
                    </Field>
                </div>
            </FormSection>

            <FormSection
                title="Identity"
                description="Never shown on the public directory. Date of birth is required; the other two are asked for only if they have them to hand."
            >
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field id="date-of-birth" label="Date of birth" error={err('date_of_birth')}>
                        <Input
                            id="date-of-birth"
                            type="date"
                            max={TODAY}
                            value={form.date_of_birth}
                            disabled={disabled}
                            aria-invalid={Boolean(err('date_of_birth'))}
                            onChange={(e) => set('date_of_birth', e.target.value)}
                        />
                    </Field>
                    <Field id="blood-group" label="Blood group" optional error={err('blood_group')}>
                        <Select
                            id="blood-group"
                            value={form.blood_group}
                            disabled={disabled}
                            onChange={(e) => set('blood_group', e.target.value)}
                        >
                            <option value="">Not known</option>
                            {BLOOD_GROUPS.map((group) => (
                                <option key={group} value={group}>
                                    {group}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field
                        id="nid-number"
                        label="NID or birth certificate no."
                        optional
                        hint="NID (10, 13 or 17 digits) or birth registration number (16 or 17)."
                        error={err('nid_number')}
                    >
                        <Input
                            id="nid-number"
                            inputMode="numeric"
                            value={form.nid_number}
                            disabled={disabled}
                            aria-invalid={Boolean(err('nid_number'))}
                            onChange={(e) => set('nid_number', e.target.value)}
                        />
                    </Field>
                </div>
            </FormSection>
        </>
    );
}
