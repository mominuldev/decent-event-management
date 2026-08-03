export interface EventSetting {
    key: string;
    group: string;
    value: string | null;
    typed_value: string | number | boolean | Record<string, unknown> | unknown[] | null;
    label: string;
    description: string | null;
}

export type SettingsByGroup = Record<string, EventSetting[]>;
