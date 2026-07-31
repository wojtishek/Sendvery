<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Who may be sent a sign-in link.
 *
 * Sign-in and registration are the same request (see LoginController), so this
 * is not only an authentication gate — it is the only gate on who can create an
 * account. The hosted product wants that door open; a self-hosted instance is
 * reachable by anyone who finds it, and there every address that receives a
 * link ends up owning a team.
 *
 * Two lists, either or both: named addresses, and domains whose every address
 * is admitted. Both empty is the default and means "anyone", so nothing changes
 * for a deployment that sets neither. A stranger is refused when the lists are
 * non-empty and match nothing.
 *
 * They are kept apart rather than merged into one list with a marker because
 * the refusal is silent by design, so a typo has no symptom to debug. In one
 * list, `example.com` written where `@example.com` was meant would quietly
 * become an address that can never match — or, read the other way, a mistyped
 * address would quietly become a domain and admit everyone behind it. An entry
 * cannot change meaning when its meaning is the list it sits in.
 *
 * Domains match exactly: `example.com` does not admit `user@mail.example.com`.
 *
 * Team invitations run through the same door: AcceptInvitationController marks
 * the invitation used and redirects to the magic-link form, where account
 * creation actually happens. So an invited teammate must be covered here too.
 * That is what locking the door means, but it is the half nobody expects.
 */
final readonly class SignInAllowlist
{
    /** @var list<string> */
    private array $addresses;

    /** @var list<string> */
    private array $domains;

    public function __construct(
        #[Autowire(env: 'SENDVERY_ALLOWED_EMAILS')]
        string $allowedEmails,
        #[Autowire(env: 'SENDVERY_ALLOWED_EMAIL_DOMAINS')]
        string $allowedDomains = '',
    ) {
        $this->addresses = self::entries($allowedEmails);

        // A domain copied over from the address list arrives as "@example.com".
        // Honouring the "@" literally would compare it against a domain that
        // never has one, and the entry would silently admit nobody.
        $this->domains = array_values(array_filter(
            array_map(
                static fn (string $entry): string => ltrim($entry, '@'),
                self::entries($allowedDomains),
            ),
            static fn (string $entry): bool => '' !== $entry,
        ));
    }

    public function permits(string $email): bool
    {
        if ([] === $this->addresses && [] === $this->domains) {
            return true;
        }

        $email = strtolower(trim($email));

        if (in_array($email, $this->addresses, true)) {
            return true;
        }

        $at = strrpos($email, '@');

        return false !== $at && in_array(substr($email, $at + 1), $this->domains, true);
    }

    /**
     * @return list<string>
     */
    private static function entries(string $list): array
    {
        return array_values(array_filter(
            array_map(
                static fn (string $entry): string => strtolower(trim($entry)),
                explode(',', $list),
            ),
            static fn (string $entry): bool => '' !== $entry,
        ));
    }
}
