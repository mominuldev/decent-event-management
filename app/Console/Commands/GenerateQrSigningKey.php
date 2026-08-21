<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Provisions an Ed25519 keypair for QrSigner (docs/06 §6.5).
 *
 * `--if-missing` is the fresh-checkout path (wired into `composer setup`):
 * writes a brand-new keypair into .env only when QR_SIGNING_PRIVATE_KEY is
 * currently blank, so every environment gets its own key rather than a
 * secret shared across every clone of this repository.
 *
 * Without the flag, this is the rotation path: it prints a new keypair for
 * an operator to add to QR_SIGNING_PRIVATE_KEYS (or the secret manager
 * behind it) *alongside* the current key. That step is deliberately inert —
 * making key material available signs nothing.
 *
 * The staged part of rotation (publish → confirm every scanner device has
 * synced → only then sign with it, Super Admin and re-auth gated, docs/06
 * §6.5) now lives behind /api/v1/admin/qr-signing/keys and the admin
 * console, where the ordering is enforced rather than remembered. This
 * command no longer describes an .env-editing procedure, because following
 * one by hand is exactly what that ordering gate exists to replace.
 */
class GenerateQrSigningKey extends Command
{
    protected $signature = 'qr-signing:generate-key {--if-missing : Only write to .env when QR_SIGNING_PRIVATE_KEY is currently blank}';

    protected $description = 'Generate an Ed25519 keypair for QR ticket signing';

    public function handle(): int
    {
        if ($this->option('if-missing')) {
            return $this->generateIfMissing();
        }

        $this->printNewKeypair();

        return self::SUCCESS;
    }

    private function generateIfMissing(): int
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->comment('No .env file present — skipping (nothing to write into).');

            return self::SUCCESS;
        }

        $env = file_get_contents($envPath);

        if ($env !== false && preg_match('/^QR_SIGNING_PRIVATE_KEY=(.+)$/m', $env, $m) && trim($m[1]) !== '') {
            $this->comment('QR_SIGNING_PRIVATE_KEY is already set — leaving it untouched.');

            return self::SUCCESS;
        }

        $keyId = 'key-'.Str::random(8);
        [$secretB64] = $this->newKeypair();

        $env = (string) $env;
        $env = preg_match('/^QR_SIGNING_PRIVATE_KEY=.*$/m', $env)
            ? preg_replace('/^QR_SIGNING_PRIVATE_KEY=.*$/m', "QR_SIGNING_PRIVATE_KEY={$secretB64}", $env)
            : $env."\nQR_SIGNING_PRIVATE_KEY={$secretB64}\n";

        $env = preg_match('/^QR_SIGNING_KEY_ID=.*$/m', (string) $env)
            ? preg_replace('/^QR_SIGNING_KEY_ID=.*$/m', "QR_SIGNING_KEY_ID={$keyId}", (string) $env)
            : $env."QR_SIGNING_KEY_ID={$keyId}\n";

        file_put_contents($envPath, $env);

        $this->info("Generated and wrote a new QR signing keypair ({$keyId}) to .env.");

        return self::SUCCESS;
    }

    private function printNewKeypair(): void
    {
        $keyId = 'key-'.Str::random(8);
        [$secretB64, $publicB64] = $this->newKeypair();

        $this->info("New QR signing key: {$keyId}");
        $this->newLine();
        $this->comment('1. Add this to QR_SIGNING_PRIVATE_KEYS, keeping any existing entries:');
        $this->line("   QR_SIGNING_PRIVATE_KEYS='".json_encode([$keyId => $secretB64], JSON_UNESCAPED_SLASHES)."'");
        $this->newLine();
        $this->line("   (public half, for reference — not a secret: {$publicB64})");
        $this->newLine();
        $this->comment('2. Deploy. Adding a key here signs nothing on its own; it only makes the key');
        $this->line('   available to be activated later.');
        $this->newLine();
        $this->comment('3. Finish the rotation in the admin console under QR Signing Keys, or via the API:');
        $this->line("     POST /api/v1/admin/qr-signing/keys              {\"key_id\": \"{$keyId}\"}");
        $this->line('     GET  /api/v1/admin/qr-signing/keys              (watch every device confirm the new key)');
        $this->line('     POST /api/v1/admin/qr-signing/keys/{ulid}/activate');
        $this->newLine();
        $this->comment('Activation refuses while any active scanner device has not synced since the key');
        $this->line('was published — that ordering is what stops a rotation breaking the gate. Event');
        $this->line('Managers are notified automatically once it completes (docs/06 §6.5).');
    }

    /**
     * @return array{0: string, 1: string} [secretKeyBase64, publicKeyBase64]
     */
    private function newKeypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);

        return [base64_encode($secret), base64_encode($public)];
    }
}
