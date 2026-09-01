<?php

namespace App\Modules\StoragePlans\Services;

use App\Modules\StoragePlans\Contracts\GooglePlayClientInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GooglePlayDeveloperClient implements GooglePlayClientInterface
{
    private ?array $credentials = null;

    private ?string $accessToken = null;

    private int $accessTokenExpiresAt = 0;

    public function isConfigured(): bool
    {
        return $this->loadCredentials() !== null;
    }

    public function getSubscription(string $productId, string $purchaseToken): array
    {
        $package = (string) config('google_play.package_name');
        $url = sprintf(
            'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptions/%s/tokens/%s',
            rawurlencode($package),
            rawurlencode($productId),
            rawurlencode($purchaseToken),
        );

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->get($url);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'purchase_token' => ['Google Play could not verify this purchase.'],
            ]);
        }

        /** @var array<string, mixed> $raw */
        $raw = $response->json() ?? [];

        return [
            'product_id' => $productId,
            'expiry_time_millis' => (int) ($raw['expiryTimeMillis'] ?? 0),
            'payment_state' => isset($raw['paymentState']) ? (int) $raw['paymentState'] : null,
            'acknowledgement_state' => (int) ($raw['acknowledgementState'] ?? 0),
            'auto_renewing' => (bool) ($raw['autoRenewing'] ?? false),
            'obfuscated_external_account_id' => isset($raw['obfuscatedExternalAccountId'])
                ? (string) $raw['obfuscatedExternalAccountId']
                : null,
            'cancel_reason' => isset($raw['cancelReason']) ? (int) $raw['cancelReason'] : null,
            'raw' => $raw,
        ];
    }

    public function acknowledgeSubscription(string $productId, string $purchaseToken): void
    {
        $package = (string) config('google_play.package_name');
        $url = sprintf(
            'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/%s/purchases/subscriptions/%s/tokens/%s:acknowledge',
            rawurlencode($package),
            rawurlencode($productId),
            rawurlencode($purchaseToken),
        );

        Http::withToken($this->accessToken())
            ->acceptJson()
            ->post($url, []);
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null && $this->accessTokenExpiresAt > time() + 30) {
            return $this->accessToken;
        }

        $credentials = $this->loadCredentials();
        if ($credentials === null) {
            throw ValidationException::withMessages([
                'payment' => ['Google Play Billing is not configured on the server.'],
            ]);
        }

        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']) ?: '');
        $claim = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/androidpublisher',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]) ?: '');

        $unsigned = $header.'.'.$claim;
        $signature = '';
        openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = $unsigned.'.'.$this->base64UrlEncode($signature);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'payment' => ['Unable to authenticate with Google Play.'],
            ]);
        }

        $this->accessToken = (string) $response->json('access_token');
        $this->accessTokenExpiresAt = $now + (int) ($response->json('expires_in') ?? 3600);

        return $this->accessToken;
    }

    /** @return array{client_email: string, private_key: string}|null */
    private function loadCredentials(): ?array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $clientEmail = config('google_play.client_email');
        $privateKey = config('google_play.private_key');
        if (is_string($clientEmail) && $clientEmail !== '' && is_string($privateKey) && $privateKey !== '') {
            $this->credentials = [
                'client_email' => $clientEmail,
                'private_key' => $this->normalizePrivateKey($privateKey),
            ];

            return $this->credentials;
        }

        $path = config('google_play.credentials_path');
        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            return null;
        }

        $decoded = json_decode(file_get_contents($path) ?: '', true);
        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return null;
        }

        $this->credentials = [
            'client_email' => (string) $decoded['client_email'],
            'private_key' => $this->normalizePrivateKey((string) $decoded['private_key']),
        ];

        return $this->credentials;
    }

    private function normalizePrivateKey(string $key): string
    {
        $key = trim($key);
        if (str_contains($key, '\\n')) {
            $key = str_replace('\\n', "\n", $key);
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
