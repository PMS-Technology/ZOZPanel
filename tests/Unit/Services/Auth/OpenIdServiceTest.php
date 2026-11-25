<?php

namespace Everest\Tests\Unit\Services\Auth;

use Everest\Exceptions\DisplayException;
use Everest\Services\Auth\OpenIdService;
use Everest\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use phpseclib3\Crypt\RSA;

class OpenIdServiceTest extends TestCase
{
    private function setupToken(array $overrides = []): array
    {
        $key = RSA::createKey(2048);
        $jwk = json_decode($key->getPublicKey()->toString('JWK'), true)['keys'][0];
        $jwk['kid'] = 'test-key';
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['https://issuer.example/keys' => Http::response(['keys' => [$jwk]])]);
        $config = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText($key->toString('PKCS8')), InMemory::plainText($key->getPublicKey()->toString('PKCS8')));
        $now = new \DateTimeImmutable();
        $builder = $config->builder()->withHeader('kid', 'test-key')->issuedBy($overrides['issuer'] ?? 'https://issuer.example')
            ->permittedFor($overrides['audience'] ?? 'client')->identifiedBy('id')->issuedAt($now)->expiresAt($now->modify('+5 minutes'))
            ->relatedTo('subject')->withClaim('nonce', $overrides['nonce'] ?? 'nonce')
            ->withClaim('email', 'person@example.com')->withClaim('email_verified', $overrides['verified'] ?? true);

        return [$builder->getToken($config->signer(), $config->signingKey())->toString(), ['issuer' => 'https://issuer.example', 'jwks_uri' => 'https://issuer.example/keys']];
    }

    public function test_accepts_a_signed_token_with_verified_email(): void
    {
        [$jwt, $metadata] = $this->setupToken();
        $this->assertSame('person@example.com', (new OpenIdService())->email($metadata, 'client', $jwt, 'nonce', null));
    }

    public function test_rejects_unverified_email(): void
    {
        [$jwt, $metadata] = $this->setupToken(['verified' => false]);
        $this->expectException(DisplayException::class);
        (new OpenIdService())->email($metadata, 'client', $jwt, 'nonce', null);
    }

    public function test_rejects_wrong_nonce(): void
    {
        [$jwt, $metadata] = $this->setupToken(['nonce' => 'wrong']);
        $this->expectException(DisplayException::class);
        (new OpenIdService())->email($metadata, 'client', $jwt, 'nonce', null);
    }

    public function test_rejects_wrong_audience(): void
    {
        [$jwt, $metadata] = $this->setupToken(['audience' => 'other']);
        $this->expectException(DisplayException::class);
        (new OpenIdService())->email($metadata, 'client', $jwt, 'nonce', null);
    }

    public function test_rejects_untrusted_signing_key(): void
    {
        [$jwt, $metadata] = $this->setupToken();
        $other = RSA::createKey(2048);
        $jwk = json_decode($other->getPublicKey()->toString('JWK'), true)['keys'][0];
        $jwk['kid'] = 'test-key';
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['https://issuer.example/keys' => Http::response(['keys' => [$jwk]])]);
        $this->expectException(DisplayException::class);
        (new OpenIdService())->email($metadata, 'client', $jwt, 'nonce', null);
    }
}
