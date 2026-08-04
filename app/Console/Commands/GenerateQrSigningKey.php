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
 * Without the flag, this is the rotation path: it only prints the new
 * keypair. Rotation is a Super Admin, re-auth-gated, staged procedure
 * (publish the new public key, confirm every scanner device has synced it,
 * only then start signing with it — see docs/06 §6.5) — this command
 * deliberately does not automate that ordering, since running it blind
 * would risk signing with a key no device can verify yet.
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
        $this->line("QR_SIGNING_KEY_ID={$keyId}");
        $this->line("QR_SIGNING_PRIVATE_KEY={$secretB64}");
        $this->newLine();
        $this->comment('Rotation procedure (docs/06 §6.5) — do not skip the ordering:');
        $this->line('  1. Add the CURRENT active key to QR_SIGNING_PUBLIC_KEYS (old_key_id => its public key)');
        $this->line("     so tickets already printed/emailed keep verifying: public key = {$publicB64}");
        $this->line('  2. Deploy that change and confirm every scanner device has synced the manifest');
        $this->line('     (its published `meta.keys` now includes the old key alongside the still-active one).');
        $this->line('  3. Only then deploy QR_SIGNING_KEY_ID / QR_SIGNING_PRIVATE_KEY above to start signing new');
        $this->line('     tickets with the new key.');
        $this->line('  4. Notify all Event Managers that the rotation completed.');
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
