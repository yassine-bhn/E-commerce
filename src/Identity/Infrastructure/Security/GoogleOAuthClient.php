<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\Model\GoogleOAuthException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class GoogleOAuthClient
{
    private const AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(GOOGLE_OAUTH_CLIENT_ID)%')]
        private string $clientId,
        #[Autowire('%env(GOOGLE_OAUTH_CLIENT_SECRET)%')]
        private string $clientSecret,
        #[Autowire('%env(GOOGLE_OAUTH_REDIRECT_URI)%')]
        private string $redirectUri,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret && '' !== $this->redirectUri;
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTHORIZATION_ENDPOINT.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);
    }

    /**
     * @return array{googleId: string, email: string, name: string}
     */
    public function fetchUser(string $code): array
    {
        $accessToken = $this->requestAccessToken($code);
        $profile = $this->requestUserInfo($accessToken);

        $sub = $profile['sub'] ?? null;
        $email = $profile['email'] ?? null;

        if (!is_string($sub) || '' === $sub || !is_string($email) || '' === $email) {
            throw GoogleOAuthException::because('The Google profile is missing a subject or an email address.');
        }

        $name = $profile['name'] ?? null;

        if (!is_string($name) || '' === $name) {
            $name = $this->localPart($email);
        }

        return [
            'googleId' => $sub,
            'email' => $email,
            'name' => $name,
        ];
    }

    private function requestAccessToken(string $code): string
    {
        try {
            $response = $this->httpClient->request('POST', self::TOKEN_ENDPOINT, [
                'body' => [
                    'code' => $code,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'redirect_uri' => $this->redirectUri,
                    'grant_type' => 'authorization_code',
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                throw GoogleOAuthException::because(sprintf('The Google token endpoint answered with HTTP %d.', $statusCode));
            }

            $payload = json_decode($response->getContent(false), true);
        } catch (TransportExceptionInterface) {
            throw GoogleOAuthException::because('The Google token endpoint is unreachable.');
        }

        if (!is_array($payload)) {
            throw GoogleOAuthException::because('The Google token endpoint returned a malformed payload.');
        }

        $accessToken = $payload['access_token'] ?? null;

        if (!is_string($accessToken) || '' === $accessToken) {
            throw GoogleOAuthException::because('The Google token endpoint did not return an access token.');
        }

        return $accessToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestUserInfo(string $accessToken): array
    {
        try {
            $response = $this->httpClient->request('GET', self::USERINFO_ENDPOINT, [
                'headers' => ['Authorization' => 'Bearer '.$accessToken],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                throw GoogleOAuthException::because(sprintf('The Google userinfo endpoint answered with HTTP %d.', $statusCode));
            }

            $payload = json_decode($response->getContent(false), true);
        } catch (TransportExceptionInterface) {
            throw GoogleOAuthException::because('The Google userinfo endpoint is unreachable.');
        }

        if (!is_array($payload)) {
            throw GoogleOAuthException::because('The Google userinfo endpoint returned a malformed payload.');
        }

        return $payload;
    }

    private function localPart(string $email): string
    {
        $localPart = strstr($email, '@', true);

        return false !== $localPart && '' !== $localPart ? $localPart : $email;
    }
}
