<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Auth;

use App\Services\Auth\SignInAllowlist;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SignInAllowlistTest extends TestCase
{
    #[Test]
    public function anUnsetAllowlistLetsAnyoneIn(): void
    {
        $allowlist = new SignInAllowlist('', '');

        self::assertTrue(
            $allowlist->permits('anyone@example.com'),
            'Both empty is the shipped default. If it locked everyone out, upgrading would silently shut the hosted product and every self-hoster who never set the variables out of their own instance.',
        );
    }

    #[Test]
    public function aWhitespaceOnlyAllowlistIsStillNoAllowlist(): void
    {
        $allowlist = new SignInAllowlist('  ,  ,', ' , ');

        self::assertTrue(
            $allowlist->permits('anyone@example.com'),
            'A list of separators with nothing between them names nobody, and reading it as "nobody may sign in" would lock an operator out of their own instance over a stray comma.',
        );
    }

    #[Test]
    public function aListedAddressIsLetIn(): void
    {
        $allowlist = new SignInAllowlist('owner@example.com', '');

        self::assertTrue($allowlist->permits('owner@example.com'));
    }

    #[Test]
    public function anUnlistedAddressIsTurnedAway(): void
    {
        $allowlist = new SignInAllowlist('owner@example.com', '');

        self::assertFalse(
            $allowlist->permits('stranger@example.com'),
            'Sign-in and registration are one request, so an address that gets a link gets a team.',
        );
    }

    #[Test]
    public function severalAddressesMayBeListed(): void
    {
        $allowlist = new SignInAllowlist('owner@example.com,colleague@example.com', '');

        self::assertTrue($allowlist->permits('owner@example.com'));
        self::assertTrue($allowlist->permits('colleague@example.com'));
        self::assertFalse($allowlist->permits('stranger@example.com'));
    }

    #[Test]
    public function spacingAroundEntriesIsForgiven(): void
    {
        $allowlist = new SignInAllowlist(' owner@example.com , colleague@example.com ', ' example.net ');

        self::assertTrue(
            $allowlist->permits('colleague@example.com'),
            'Anyone writing a list by hand puts a space after the comma. Honouring it literally would lock out the person the operator just tried to admit, and the failure is silent by design — there would be nothing to debug from.',
        );
        self::assertTrue($allowlist->permits('anyone@example.net'));
    }

    #[Test]
    public function addressesMatchRegardlessOfCase(): void
    {
        $allowlist = new SignInAllowlist('Owner@Example.COM', '');

        self::assertTrue(
            $allowlist->permits('owner@example.com'),
            'LoginController lowercases what the user typed before dispatching, so a list written with capitals would never match anything the handler is asked about.',
        );
        self::assertTrue($allowlist->permits('  OWNER@EXAMPLE.COM  '));
    }

    #[Test]
    public function aListedDomainAdmitsEveryAddressOnIt(): void
    {
        $allowlist = new SignInAllowlist('', 'example.com');

        self::assertTrue($allowlist->permits('owner@example.com'));
        self::assertTrue(
            $allowlist->permits('someone-who-joined-today@example.com'),
            'Admitting a domain is the point: a new colleague must not need a config change and a container restart before they can sign in.',
        );
    }

    #[Test]
    public function aDomainListAloneStillLocksTheDoor(): void
    {
        $allowlist = new SignInAllowlist('', 'example.com');

        self::assertFalse(
            $allowlist->permits('stranger@example.net'),
            'Setting only the domain list must lock the instance just as firmly as setting only the address list; an operator who fills in one of the two has locked the door.',
        );
    }

    #[Test]
    public function domainsMatchRegardlessOfCase(): void
    {
        $allowlist = new SignInAllowlist('', 'Example.COM');

        self::assertTrue($allowlist->permits('owner@example.com'));
    }

    #[Test]
    public function aDomainWrittenWithALeadingAtIsForgiven(): void
    {
        $allowlist = new SignInAllowlist('', '@example.com');

        self::assertTrue(
            $allowlist->permits('owner@example.com'),
            'The "@example.com" spelling is what anyone moving an entry over from the address list writes. Comparing it literally against a domain, which never carries an "@", would admit nobody and say nothing about why.',
        );
    }

    #[Test]
    public function aSubdomainIsNotCoveredByItsParent(): void
    {
        $allowlist = new SignInAllowlist('', 'example.com');

        self::assertFalse(
            $allowlist->permits('owner@mail.example.com'),
            'Exact matching keeps the rule readable. Admitting subdomains would mean a domain nobody vetted — one someone else may control — silently inherits the entitlement.',
        );
    }

    #[Test]
    public function theTwoListsAreAUnion(): void
    {
        $allowlist = new SignInAllowlist('contractor@gmail.com', 'example.com');

        self::assertTrue($allowlist->permits('anyone@example.com'), 'The domain list admits the company.');
        self::assertTrue($allowlist->permits('contractor@gmail.com'), 'The address list admits the one outsider, which is why naming addresses survives alongside naming domains.');
        self::assertFalse($allowlist->permits('someone-else@gmail.com'));
    }
}
