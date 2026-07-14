<?php

namespace App\Modules\Devices\Services;

/**
 * Loads Firebase service account credentials from env vars (preferred) or a JSON file (legacy).
 *
 * Preferred — set in .env (never commit):
 *   FIREBASE_PROJECT_ID=
 *   FIREBASE_CLIENT_EMAIL=
 *   FIREBASE_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"
 */
final class FirebaseCredentials
{
    /** @return array<string, mixed>|null */
    public static function resolve(): ?array
    {
        $fromEnv = self::fromEnvironment();

        if ($fromEnv !== null) {
            return $fromEnv;
        }

        return self::fromCredentialsFile(config('services.firebase.credentials_path'));
    }

    public static function isConfigured(): bool
    {
        return self::resolve() !== null;
    }

    /** @return array<string, mixed>|null */
    private static function fromEnvironment(): ?array
    {
        $projectId = config('services.firebase.project_id');
        $clientEmail = config('services.firebase.client_email');
        $privateKey = config('services.firebase.private_key');

        if (! is_string($projectId) || $projectId === ''
            || ! is_string($clientEmail) || $clientEmail === ''
            || ! is_string($privateKey) || $privateKey === '') {
            return null;
        }

        return [
            'project_id' => $projectId,
            'client_email' => $clientEmail,
            'private_key' => self::normalizePrivateKey($privateKey),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function fromCredentialsFile(mixed $path): ?array
    {
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }

        $decoded = json_decode(file_get_contents($path) ?: '', true);

        if (! is_array($decoded)
            || empty($decoded['project_id'])
            || empty($decoded['client_email'])
            || empty($decoded['private_key'])) {
            return null;
        }

        return $decoded;
    }

    private static function normalizePrivateKey(string $key): string
    {
        $key = trim($key);

        // .env often stores PEM as one line with literal \n sequences.
        if (str_contains($key, '\\n')) {
            $key = str_replace('\\n', "\n", $key);
        }

        return $key;
    }
}
