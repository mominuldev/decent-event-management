<?php

/**
 * Bootstrap credentials for the first Super Admin account, read by
 * `admin:create-super-admin --if-missing` — the deploy-time path.
 *
 * These exist because there is no HTTP route that creates a staff account
 * (routes/api/admin.php has users index/show and assign-role only), so a
 * freshly migrated database has nobody who can log in and therefore nobody
 * who can create anybody. Setting these two values lets a deploy close
 * that gap once, on its own.
 *
 * They are deliberately blank by default: `--if-missing` skips entirely
 * when they are, so an environment that provisions its admin by hand is
 * unaffected and CI never invents an account.
 *
 * SUPER_ADMIN_PASSWORD is a plaintext credential sitting in the host's
 * .env for as long as it is set. Once the account exists, delete both
 * lines — the command then finds nothing configured and skips, so removing
 * them is the intended end state rather than a loose end.
 */
return [

    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
        'name' => env('SUPER_ADMIN_NAME'),
        'phone' => env('SUPER_ADMIN_PHONE'),
    ],

];
