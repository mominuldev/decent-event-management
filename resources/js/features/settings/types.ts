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
    /**
     * A credential. The server never sends its value — not `value`, not
     * `typed_value`, both of which are null for these — so the row can only
     * be replaced, never read back. Render a password field, not a text one.
     */
    is_secret: boolean;
    /** Whether a credential is stored. Null for a non-secret row. */
    is_set: boolean | null;
    /** Last four characters behind bullets, so a reader can tell which key is stored. */
    masked_value: string | null;
    label: string;
    description: string | null;
    updated_at: string | null;
    updated_by?: string | null;
}

export type SettingsByGroup = Record<string, EventSetting[]>;

/** `GET /admin/notifications/sms-balance`. Not wrapped in a `data` envelope. */
export interface SmsBalance {
    /** False when any of the three required credentials is missing. */
    configured: boolean;
    /** Which fields are empty, by their Settings label. Empty when configured. */
    missing: string[];
    /**
     * False when this REVE deployment does not expose a balance endpoint —
     * a different thing from a balance of zero, and it must not render as one.
     */
    balance_available: boolean;
    /** BDT as the gateway reports it; null when it returns no parseable figure. */
    balance: number | null;
    /**
     * Balance divided by the configured per-segment cost. An estimate from a
     * local number, not something REVE reports — only as right as
     * `sms.cost_paisa_per_segment`.
     */
    estimated_segments: number | null;
    low_balance_threshold_paisa: number | null;
    is_low: boolean;
    recharge_url: string | null;
    checked_at: string;
}
