<?php

declare(strict_types=1);

namespace App\Services\Dns;

/**
 * Intercepts the unqualified `fsockopen()` call inside SocketSmtpProbe.
 *
 * PHP resolves an unqualified function call inside a namespace against that
 * namespace first and only then against the global one — the same mechanism
 * `phpunit.xml.dist` already relies on for `clock-mock-namespaces` and
 * `dns-mock-namespaces`. It is used here because the thing under test is *the
 * address we dial*, which nothing on this side of a real mail host can observe.
 *
 * The double models what the operating system actually does. Measured in the
 * app container on 2026-07-28:
 *
 *   fsockopen('::1', 25)                   false, "php_network_getaddresses:
 *                                          getaddrinfo for  failed"
 *   fsockopen('[::1]', 25)                 false, errno 111 Connection refused
 *   fsockopen('2606:4700:4700::1111', 25)  false, errno 111 Connection refused
 *   fsockopen('[2606:4700:4700::1111]',25) false, errno 101 Network unreachable
 *
 * An unbracketed IPv6 literal is not merely refused: `tcp://<literal>:25` is
 * not a parseable address, so the connection never reaches the host at all. The
 * double therefore answers only at the exact spelling the network stack needs.
 *
 * Defined for the whole PHPUnit process once this file is loaded, which is
 * deliberate and harmless: `config/services.php` aliases SmtpProbe to
 * FakeSmtpProbe under `when@test`, so nothing else instantiates SocketSmtpProbe
 * — and an address this double was not told about returns false, i.e. exactly
 * FakeSmtpProbe's default. The one thing it makes impossible is a real socket
 * being opened from a test.
 *
 * @param resource|null $context
 *
 * @return resource|false
 */
function fsockopen(string $hostname, int $port = -1, ?int &$errorCode = null, ?string &$errorMessage = null, ?float $timeout = null, $context = null)
{
    return \App\Tests\Unit\Services\Dns\FakeMailHost::dial($hostname);
}

namespace App\Tests\Unit\Services\Dns;

use App\Services\Dns\SocketSmtpProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A mail host on port 25 that is reachable only at the exact address spelling
 * the network stack requires, and that says whatever it is told to say.
 */
final class FakeMailHost
{
    /** @var list<string> every address SocketSmtpProbe actually dialled */
    public static array $dialled = [];

    /** @var array<string, string> address => scripted server side of the conversation */
    private static array $conversations = [];

    /** @var array<string, resource> address => server end, held open so the probe's writes are not a broken pipe */
    private static array $serverEnds = [];

    public static function reset(): void
    {
        foreach (self::$serverEnds as $serverEnd) {
            if (is_resource($serverEnd)) {
                fclose($serverEnd);
            }
        }

        self::$dialled = [];
        self::$conversations = [];
        self::$serverEnds = [];
    }

    public static function answersAt(string $address, string $conversation = "220 mx.example.test ESMTP\r\n250-mx.example.test\r\n250 STARTTLS\r\n"): void
    {
        self::$conversations[$address] = $conversation;
    }

    /**
     * Everything the probe wrote on the wire. The probe closes its own end
     * before a test asks, so the server end reads to EOF instead of blocking.
     */
    public static function heardAt(string $address): string
    {
        $serverEnd = self::$serverEnds[$address] ?? null;

        if (!is_resource($serverEnd)) {
            return '';
        }

        stream_set_blocking($serverEnd, false);

        return (string) stream_get_contents($serverEnd);
    }

    /**
     * @return resource|false
     */
    public static function dial(string $address)
    {
        self::$dialled[] = $address;

        $conversation = self::$conversations[$address] ?? null;
        if (null === $conversation) {
            return false;
        }

        // A connected socket pair behaves like a real conversation: what the
        // probe writes (EHLO) goes down the wire instead of overwriting the
        // bytes it is about to read, which a rewound memory stream would do.
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        if (false === $pair) {
            return false;
        }

        fwrite($pair[1], $conversation);
        self::$serverEnds[$address] = $pair[1];

        return $pair[0];
    }
}

final class SocketSmtpProbeTest extends TestCase
{
    protected function setUp(): void
    {
        FakeMailHost::reset();
    }

    protected function tearDown(): void
    {
        FakeMailHost::reset();
    }

    #[Test]
    public function anIpv6OnlyMailHostAnswersInsteadOfLookingUnreachable(): void
    {
        FakeMailHost::answersAt('[2001:db8::25]');

        $result = (new SocketSmtpProbe())->probe('2001:db8::25');

        self::assertTrue(
            $result->reachable,
            'An IPv6-only mail host that answers on port 25 must read as reachable. A bare IPv6 literal is not a '
            .'parseable "host:port" address, so it never reaches the host — and MxChecker then tells the user our own '
            .'egress is probably filtered, an accusation we invented.',
        );
        self::assertTrue($result->tlsSupported, 'The EHLO conversation must reach the same host the banner came from.');
        self::assertSame(
            ['[2001:db8::25]'],
            FakeMailHost::$dialled,
            'The literal has to be bracketed; leaving it bare leaves TLS support permanently unknown.',
        );
    }

    #[Test]
    public function anIpv4MailHostIsStillDialledExactlyAsGiven(): void
    {
        FakeMailHost::answersAt('203.0.113.25');

        $result = (new SocketSmtpProbe())->probe('203.0.113.25');

        self::assertTrue($result->reachable);
        self::assertSame(
            ['203.0.113.25'],
            FakeMailHost::$dialled,
            'Bracketing an IPv4 address would break every mail host we can currently reach.',
        );
    }

    #[Test]
    public function aMailHostThatNeverAnswersIsReportedUnreachable(): void
    {
        $result = (new SocketSmtpProbe())->probe('203.0.113.99');

        self::assertFalse($result->reachable);
        self::assertNull($result->tlsSupported, 'We never spoke to the host, so whether it supports STARTTLS is unknown rather than false.');
    }

    #[Test]
    public function aHostThatDoesNotGreetUsWithTwoTwentyIsNotAMailServer(): void
    {
        FakeMailHost::answersAt('203.0.113.80', "HTTP/1.1 400 Bad Request\r\n");

        $result = (new SocketSmtpProbe())->probe('203.0.113.80');

        self::assertFalse($result->reachable, 'Something answering on port 25 that does not open with 220 is not an SMTP server.');
    }

    #[Test]
    public function aMailServerWithoutStartTlsIsReportedWithoutTls(): void
    {
        FakeMailHost::answersAt('203.0.113.26', "220 old.example.test ESMTP\r\n250 old.example.test\r\n");

        $result = (new SocketSmtpProbe())->probe('203.0.113.26');

        self::assertTrue($result->reachable);
        self::assertFalse($result->tlsSupported);
    }

    /**
     * A greeting may span several lines, and Postfix's postscreen makes that the
     * common case: it answers with a partial `220-` line, then withholds the
     * final `220 ` line while it runs its deep protocol tests. Reading one line
     * and speaking immediately is what postscreen logs as PREGREET and scores as
     * a spambot.
     */
    #[Test]
    public function aGreetingSpreadOverSeveralLinesIsReadToItsEndBeforeWeSpeak(): void
    {
        FakeMailHost::answersAt(
            '203.0.113.27',
            "220-mx.example.test ESMTP Postfix\r\n220 mx.example.test ready\r\n250-mx.example.test\r\n250 STARTTLS\r\n",
        );

        $result = (new SocketSmtpProbe())->probe('203.0.113.27');

        self::assertTrue($result->reachable);
        self::assertTrue(
            $result->tlsSupported,
            'Stopping at the first greeting line leaves the rest of the banner in the buffer, where it is then read as '
            .'the EHLO response — so STARTTLS goes unseen and a perfectly modern mail server is reported as offering no TLS.',
        );
    }

    #[Test]
    public function theProbeGreetsAndSignsOffOnASingleConnection(): void
    {
        FakeMailHost::answersAt('203.0.113.28');

        (new SocketSmtpProbe())->probe('203.0.113.28');

        self::assertSame(
            ['203.0.113.28'],
            FakeMailHost::$dialled,
            'Reading the banner on one connection and running EHLO on a second doubles our footprint on every mail host '
            .'we measure, and the banner-only connection is dropped mid-test — which postscreen records as a HANGUP.',
        );
        self::assertSame(
            "EHLO sendvery.com\r\nQUIT\r\n",
            FakeMailHost::heardAt('203.0.113.28'),
            'A probe that walks away without QUIT is scored as an abandoned session by the host it just measured.',
        );
    }
}
