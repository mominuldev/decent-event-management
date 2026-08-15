/** Matches `EventSetting::typedValue()`'s `match` arms on the server. */
export type SettingType = 'string' | 'int' | 'money' | 'bool' | 'datetime' | 'json';

export interface EventSetting {
    key: string;
    group: string;
    value: string | null;
    typed_value: string | number | boolean | Record<string, unknown> | unknown[] | null;
    /**
     * The server's declared type. Render the editor from this, never from
     * `typeof typed_value` — a `datetime` and a `string` are the same JSON
     * type, so guessing puts a raw ISO timestamp in a plain text box.
     */
    type: SettingType;
    /** True when the public website reads this value. */
    is_public: boolean;
    label: string;
    description: string | null;
    updated_at: string | null;
    updated_by?: string | null;
}

export type SettingsByGroup = Record<string, EventSetting[]>;
