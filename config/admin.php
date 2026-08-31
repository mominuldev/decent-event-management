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
 * The password is deliberately blank by default. With no password
 * configured and no --generate-password, `--if-missing` skips entirely, so
 * an environment that provisions its admin by hand is unaffected and CI
 * never invents an account.
 *
 * The email is NOT blank, and that is what makes the deploy work with no
 * configuration at all: with --generate-password the deploy needs an
 * address to create the account at, and this one is already committed in
 * DatabaseSeeder, so defaulting to it exposes nothing new. An address is
 * a login identifier here, not a secret; the password is the secret, and
 * that is the half that is never committed.
 *
 * SUPER_ADMIN_PASSWORD is a plaintext credential sitting in the host's
 * .env for as long as it is set. Once the account exists, delete the line
 * — the command finds the account already there and skips regardless, so
 * removing it is the intended end state rather than a loose end.
 */
return [

    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL', 'mominulfed@gmail.com'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
        'name' => env('SUPER_ADMIN_NAME'),
        'phone' => env('SUPER_ADMIN_PHONE'),
    ],

];
