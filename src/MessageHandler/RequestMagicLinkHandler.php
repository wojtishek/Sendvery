<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\MagicLinkToken;
use App\Message\RequestMagicLink;
use App\Repository\MagicLinkTokenRepository;
use App\Repository\UserRepository;
use App\Services\Auth\SignInAllowlist;
use App\Services\MagicLinkAbuseMonitor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

#[AsMessageHandler]
final readonly class RequestMagicLinkHandler
{
    private const int TOKEN_EXPIRY_MINUTES = 15;
    private const int MAX_REQUESTS_PER_HOUR = 5;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private MagicLinkTokenRepository $magicLinkTokenRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private Environment $twig,
        private MagicLinkAbuseMonitor $abuseMonitor,
        private SignInAllowlist $allowlist,
    ) {
    }

    public function __invoke(RequestMagicLink $message): void
    {
        // Before anything is written or sent: a blocked address leaves no token
        // row and no abuse-monitor trail, and the caller cannot tell it apart
        // from a delivered link — LoginController already answers every outcome
        // with the same "check your email" page.
        if (!$this->allowlist->permits($message->email)) {
            return;
        }

        $now = $this->clock->now();
        $oneHourAgo = $now->modify('-1 hour');

        $recentCount = $this->magicLinkTokenRepository->countRecentByEmail(
            $message->email,
            $oneHourAgo,
        );

        if ($recentCount >= self::MAX_REQUESTS_PER_HOUR) {
            return;
        }

        $this->abuseMonitor->recordRequest(
            $message->email,
            $message->requestedIp,
            $message->requestedUserAgent,
            $now,
        );

        $user = $this->userRepository->findByEmail($message->email);
        $token = bin2hex(random_bytes(32));
        $expiresAt = $now->modify('+'.self::TOKEN_EXPIRY_MINUTES.' minutes');

        $magicLinkToken = new MagicLinkToken(
            id: $message->tokenId,
            email: $message->email,
            token: $token,
            expiresAt: $expiresAt,
            createdAt: $now,
            user: $user,
            requestedIp: $message->requestedIp,
            requestedUserAgent: $message->requestedUserAgent,
        );

        $this->entityManager->persist($magicLinkToken);

        $verifyUrl = $this->urlGenerator->generate(
            'auth_verify_magic_link',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('emails/magic_link.html.twig', [
            'verifyUrl' => $verifyUrl,
            'expiryMinutes' => self::TOKEN_EXPIRY_MINUTES,
        ]);

        $email = (new Email())
            ->to($message->email)
            ->subject('Sign in to Sendvery')
            ->html($html)
            ->text($this->renderEmailText($verifyUrl));

        $this->mailer->send($email);
    }

    private function renderEmailText(string $verifyUrl): string
    {
        $minutes = self::TOKEN_EXPIRY_MINUTES;

        return <<<TEXT
        Sign in to Sendvery

        Click this link to sign in: {$verifyUrl}

        This link expires in {$minutes} minutes and can only be used once.

        Didn't request this? You can safely ignore this email — we won't sign
        anyone in without the link above.
        TEXT;
    }
}
