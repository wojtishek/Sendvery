<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MagicLinkToken;
use App\Entity\User;
use App\Message\RequestMagicLink;
use App\MessageHandler\RequestMagicLinkHandler;
use App\Services\Auth\SignInAllowlist;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class RequestMagicLinkHandlerTest extends IntegrationTestCase
{
    public function testCreatesTokenAndSendsEmail(): void
    {
        $handler = self::getContainer()->get(RequestMagicLinkHandler::class);
        assert($handler instanceof RequestMagicLinkHandler);

        $tokenId = Uuid::uuid7();
        $email = 'magic-'.$tokenId->toString().'@example.com';

        $handler(new RequestMagicLink(
            tokenId: $tokenId,
            email: $email,
        ));

        $em = $this->getService(EntityManagerInterface::class);
        $token = $em->find(MagicLinkToken::class, $tokenId);

        self::assertNotNull($token);
        self::assertSame($email, $token->email);
        self::assertNull($token->user);
        self::assertNull($token->usedAt);
        self::assertNotEmpty($token->token);
        self::assertSame(64, strlen($token->token));
    }

    public function testRecordsRequestOriginOnToken(): void
    {
        $handler = self::getContainer()->get(RequestMagicLinkHandler::class);
        assert($handler instanceof RequestMagicLinkHandler);

        $tokenId = Uuid::uuid7();

        $handler(new RequestMagicLink(
            tokenId: $tokenId,
            email: 'forensics-'.$tokenId->toString().'@example.com',
            requestedIp: '203.0.113.7',
            requestedUserAgent: 'Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0)',
        ));

        $em = $this->getService(EntityManagerInterface::class);
        $token = $em->find(MagicLinkToken::class, $tokenId);

        self::assertNotNull($token);
        self::assertSame('203.0.113.7', $token->requestedIp, 'The token must keep the origin IP — during the July 2026 abuse campaign the proxy access logs were the only IP source and they rotate away.');
        self::assertSame('Mozilla/5.0 (Windows NT 6.1; WOW64; Trident/7.0)', $token->requestedUserAgent, 'The token must keep the User-Agent — a rotating pool of decade-old UAs is the campaign fingerprint.');
    }

    public function testLinksTokenToExistingUser(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $userId = Uuid::uuid7();
        $email = 'existing-'.$userId->toString().'@example.com';
        $user = new User(
            id: $userId,
            email: $email,
            createdAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);
        $em->flush();
        $em->clear();

        $handler = self::getContainer()->get(RequestMagicLinkHandler::class);
        assert($handler instanceof RequestMagicLinkHandler);

        $tokenId = Uuid::uuid7();

        $handler(new RequestMagicLink(
            tokenId: $tokenId,
            email: $email,
        ));

        $em->clear();
        $token = $em->find(MagicLinkToken::class, $tokenId);

        self::assertNotNull($token);
        self::assertNotNull($token->user);
        self::assertSame($userId->toString(), $token->user->id->toString());
    }

    public function testRateLimitsRequestsPerEmail(): void
    {
        $handler = self::getContainer()->get(RequestMagicLinkHandler::class);
        assert($handler instanceof RequestMagicLinkHandler);

        $email = 'ratelimit-'.Uuid::uuid7()->toString().'@example.com';

        // Send 5 requests (max allowed)
        for ($i = 0; $i < 5; ++$i) {
            $handler(new RequestMagicLink(
                tokenId: Uuid::uuid7(),
                email: $email,
            ));
        }

        // 6th request should be silently ignored
        $tokenId = Uuid::uuid7();
        $handler(new RequestMagicLink(
            tokenId: $tokenId,
            email: $email,
        ));

        $em = $this->getService(EntityManagerInterface::class);
        $token = $em->find(MagicLinkToken::class, $tokenId);

        self::assertNull($token);
    }

    public function testAnAddressOutsideTheAllowlistIsSentNothing(): void
    {
        self::getContainer()->set(SignInAllowlist::class, new SignInAllowlist('owner@example.com', ''));

        $handler = self::getContainer()->get(RequestMagicLinkHandler::class);
        assert($handler instanceof RequestMagicLinkHandler);

        $tokenId = Uuid::uuid7();

        $handler(new RequestMagicLink(
            tokenId: $tokenId,
            email: 'stranger-'.$tokenId->toString().'@example.com',
        ));

        $em = $this->getService(EntityManagerInterface::class);

        self::assertNull(
            $em->find(MagicLinkToken::class, $tokenId),
            'A stranger who reaches a self-hosted instance must get no token and no email. Sign-in and registration are one request, so a delivered link is an account — and the caller cannot tell this apart from success, which is what stops the endpoint from reporting who is allowed in.',
        );
    }
}
