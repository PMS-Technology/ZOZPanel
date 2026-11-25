<?php

namespace Everest\Services\Auth;

use Everest\Exceptions\DisplayException;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use phpseclib3\Crypt\RSA;
use phpseclib3\Math\BigInteger;

class OpenIdService
{
    public function discovery(string $issuer): array
    {
        $this->https($issuer);
        $data = $this->get(rtrim($issuer, '/') . '/.well-known/openid-configuration');
        if (($data['issuer'] ?? null) !== $issuer) {
            throw new DisplayException('OIDC issuer does not match discovery metadata.');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $key) {
            $this->https($data[$key] ?? null);
        }

        return $data;
    }

    public function email(array $metadata, string $clientId, string $jwt, string $nonce, ?string $accessToken): string
    {
        try {
            $config = Configuration::forUnsecuredSigner();
            $token = $config->parser()->parse($jwt);
            $kid = $token->headers()->get('kid');
            if ($token->headers()->get('alg') !== 'RS256' || !is_string($kid) || $kid === '') {
                throw new \RuntimeException('Unsupported ID token signature.');
            }
            $keys = $this->get($metadata['jwks_uri'])['keys'] ?? [];
            $matches = array_values(array_filter($keys, fn ($key) => is_array($key) && ($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA' && (!isset($key['use']) || $key['use'] === 'sig') && (!isset($key['alg']) || $key['alg'] === 'RS256')));
            if (count($matches) !== 1) {
                throw new \RuntimeException('Invalid signing key.');
            }
            $decode = static function ($value): string {
                if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
                    throw new \RuntimeException('Invalid RSA key.');
                }
                $decoded = base64_decode(strtr($value, '-_', '+/'), true);
                if ($decoded === false) {
                    throw new \RuntimeException('Invalid RSA key.');
                }
                return $decoded;
            };
            $key = RSA::loadPublicKey(['n' => new BigInteger($decode($matches[0]['n'] ?? null), 256), 'e' => new BigInteger($decode($matches[0]['e'] ?? null), 256)]);
            if ($key->getLength() < 2048 || !$config->validator()->validate($token, new SignedWith(new Sha256(), InMemory::plainText($key->toString('PKCS8'))))) {
                throw new \RuntimeException('Invalid signature.');
            }
            $claims = $token->claims();
            $audience = $claims->get('aud', []);
            $now = new \DateTimeImmutable();
            $exp = $claims->get('exp');
            $iat = $claims->get('iat');
            $nbf = $claims->get('nbf');
            $subject = $claims->get('sub');
            if ($claims->get('iss') !== $metadata['issuer'] || $audience !== [$clientId] || ($claims->has('azp') && $claims->get('azp') !== $clientId)
                || !$exp instanceof \DateTimeImmutable || $exp <= $now || !$iat instanceof \DateTimeImmutable || $iat > $now->modify('+60 seconds')
                || ($nbf !== null && (!$nbf instanceof \DateTimeImmutable || $nbf > $now->modify('+60 seconds')))
                || !is_string($subject) || $subject === '' || !is_string($claims->get('nonce')) || !hash_equals($nonce, $claims->get('nonce'))) {
                throw new \RuntimeException('Invalid ID token claims.');
            }
            $email = $claims->get('email');
            $verified = $claims->get('email_verified');
            if ((!is_string($email) || $verified !== true) && is_string($accessToken) && isset($metadata['userinfo_endpoint'])) {
                $this->https($metadata['userinfo_endpoint']);
                $info = Http::acceptJson()->withToken($accessToken)->timeout(10)->withOptions(['allow_redirects' => false])->get($metadata['userinfo_endpoint'])->throw()->json();
                if (!is_array($info) || ($info['sub'] ?? null) !== $subject) {
                    throw new \RuntimeException('Invalid userinfo subject.');
                }
                $email = $info['email'] ?? null;
                $verified = $info['email_verified'] ?? null;
            }
            if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || $verified !== true) {
                throw new \RuntimeException('A verified email address is required.');
            }

            return $email;
        } catch (\Throwable $e) {
            throw new DisplayException('OIDC authentication failed or the provider did not confirm a verified email address.', $e);
        }
    }

    private function get(string $url): array
    {
        $data = Http::acceptJson()->timeout(10)->withOptions(['allow_redirects' => false])->get($url)->throw()->json();
        if (!is_array($data)) {
            throw new DisplayException('Invalid OIDC provider response.');
        }

        return $data;
    }

    private function https(mixed $url): void
    {
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new DisplayException('OIDC provider URLs must use HTTPS.');
        }
    }
}
