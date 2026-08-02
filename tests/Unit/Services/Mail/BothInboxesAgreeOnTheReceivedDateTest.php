<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Mail;

use App\Services\Reports\ImapCentralInboxClient;
use App\Tests\TestSupport\ProjectSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Message;

/**
 * Both ingestion paths store an immutable received date, and they have to
 * derive it the same way.
 *
 * Webklex parses the Date header into Carbon, and `Carbon::toDate()` returns a
 * mutable \DateTime. The central inbox converted it; the BYO poller wrote its
 * own line and did not, so `MailMessage::__construct()` rejected the argument
 * and every BYO mailbox poll died on the first message carrying a Date header
 * — which is every real one.
 *
 * The guard that looked like it covered this, `->first()?->toDate() ?? new
 * \DateTimeImmutable()`, reached neither case. A parsed date is a Carbon, so
 * the fallback never fires and a mutable \DateTime goes straight through; and
 * a *missing* header makes `first()` return `false`, not `null`, so `?->` does
 * not short-circuit and the call fatals on a bool instead. The one expression
 * was wrong in both directions and could look right in neither.
 *
 * WHAT THIS FILE CANNOT REACH is what the sibling file already states for
 * `fullRawEml()`: `ImapMailClient::fetchDmarcReports()` needs a live IMAP
 * server and builds its `ClientManager` inline, so there is no seam. Covered
 * here is the shared derivation itself, against real parsed messages rather
 * than doubles — Webklex exposes `getDate()` through `__call`, so it cannot be
 * stubbed and a test that tried would only be testing PHPUnit. Plus a source
 * assertion that the BYO path still calls it, because re-deriving it inline is
 * exactly the mistake this fixes and nothing in a green suite would say so.
 */
final class BothInboxesAgreeOnTheReceivedDateTest extends TestCase
{
    #[Test]
    public function aParsedDateHeaderBecomesAnImmutableDate(): void
    {
        $message = self::messageWithHeaders("Date: Fri, 31 Jul 2026 10:15:00 +0000\r\n");

        $receivedAt = ImapCentralInboxClient::receivedAt($message);

        self::assertSame(
            '2026-07-31 10:15:00',
            $receivedAt->format('Y-m-d H:i:s'),
            'The moment the mail was received has to survive the conversion, or every report is filed under the wrong day.',
        );
    }

    #[Test]
    public function aMessageWithNoDateHeaderFallsBackToNowRatherThanBlowingUp(): void
    {
        $before = new \DateTimeImmutable();

        $receivedAt = ImapCentralInboxClient::receivedAt(self::messageWithHeaders(''));

        self::assertGreaterThanOrEqual(
            $before->getTimestamp(),
            $receivedAt->getTimestamp(),
            'A missing Date header is a malformed sender, not a reason to abandon the rest of the batch — the arrival is filed as now. Webklex answers that case with false rather than null, which is why the null-safe operator it used to rely on was never enough.',
        );
    }

    #[Test]
    public function theByoPollerStillSharesTheCentralInboxsDerivation(): void
    {
        // A source assertion, deliberately: there is no seam to observe this
        // through, and the mistake it guards against is the one that shipped —
        // a plausible-looking inline expression that throws only once a real
        // message arrives.
        $client = (string) file_get_contents(ProjectSource::projectDir().'/src/Services/Mail/ImapMailClient.php');

        self::assertStringContainsString(
            'date: ImapCentralInboxClient::receivedAt($message)',
            $client,
            'The BYO path must keep sharing the central inbox\'s derivation of the received date rather than reaching for getDate() itself.',
        );
    }

    private static function messageWithHeaders(string $extraHeaders): Message
    {
        return Message::fromString(
            "Message-ID: <report@example.com>\r\nSubject: Report Domain: example.com\r\n"
            .$extraHeaders
            ."Content-Type: text/plain\r\n\r\nreport-bytes",
        );
    }
}
