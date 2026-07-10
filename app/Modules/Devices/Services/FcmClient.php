<?php

namespace App\Modules\Devices\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends FCM HTTP v1 messages using Firebase service account credentials from .env.
 */
class FcmClient
{
    private ?array $credentials = null;

    public function isConfigured(): bool
    {
        return FirebaseCredentials::isConfigured();
    }

    /** @param  array<string, mixed>  $data */
    public function send(string $deviceToken, string $title, string $body, array $data, int $badge): bool
    {
        if (! $this->isConfigured() || $deviceToken === '') {
            return false;
        }

        try {
            $accessToken = $this->accessToken();
            $projectId = $this->projectId();

            $payload = [
                'message' => [
                    'token' => $deviceToken,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    'data' => collect($data)->map(fn ($v) => (string) $v)->all(),
                    'android' => [
                        'notification' => [
                            'channel_id' => 'messages',
                            'notification_count' => $badge,
                        ],
                    ],
                    'apns' => [
                        'payload' => [
                            'aps' => [
                                'badge' => $badge,
                                'sound' => 'default',
                            ],
                        ],
                    ],
                ],
            ];

            $response = Http::withToken($accessToken)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", $payload);

            if (! $response->successful()) {
                Log::warning('FCM send failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            Log::warning('FCM send error', ['message' => $exception->getMessage()]);

            return false;
        }
    }

    private function accessToken(): string
    {
        $credentials = $this->loadCredentials();
        $now = time();

        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $unsigned = "{$header}.{$claim}";
        $signature = '';
        openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
        $jwt = $unsigned.'.'.$this->base64UrlEncode($signature);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Unable to obtain FCM access token.');
        }

        return (string) $response->json('access_token');
    }

    private function projectId(): string
    {
        return (string) ($this->loadCredentials()['project_id'] ?? '');
    }

    /** @return array<string, mixed> */
    private function loadCredentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $resolved = FirebaseCredentials::resolve();

        if ($resolved === null) {
            throw new \RuntimeException('Firebase credentials are not configured.');
        }

        $this->credentials = $resolved;

        return $this->credentials;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
