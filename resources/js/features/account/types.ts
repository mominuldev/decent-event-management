export interface UpdateProfilePayload {
    name: string;
    email: string;
    phone: string | null;
}

export interface ChangePasswordPayload {
    current_password: string;
    password: string;
    password_confirmation: string;
}

export interface ChangePasswordResult {
    message: string;
    /** Sessions on other devices that were signed out by the change. */
    other_sessions_revoked: number;
}

/** Mirrors ChangePasswordRequest::MIN_LENGTH — keep the two in step. */
export const MIN_PASSWORD_LENGTH = 12;
