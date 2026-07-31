<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\SmtpProbeResult;

/**
 * Production SmtpProbe. Opens one TCP connection to port 25, reads the greeting
 * to its final line, issues EHLO and inspects the response for the STARTTLS
 * capability advertisement.
 */
final readonly class SocketSmtpProbe implements SmtpProbe
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    /**
     * Reading a greeting to its end means waiting out `postscreen_greet_wait`,
     * 6 seconds on a stock Postfix. The connect timeout does not cover reads, so
     * without this the wait is `default_socket_timeout` — 60 seconds — and one
     * tarpitting MX stalls the scan behind it.
     */
    private const int READ_TIMEOUT_SECONDS = 10;

    private const int READ_BUFFER = 1024;

    public function probe(string $ip): SmtpProbeResult
    {
        $socket = $this->dial($ip);

        if (null === $socket) {
            return SmtpProbeResult::unreachable();
        }

        try {
            if (!str_starts_with($this->readReply($socket), '220')) {
                return SmtpProbeResult::unreachable();
            }

            fwrite($socket, "EHLO sendvery.com\r\n");
            $ehloResponse = $this->readReply($socket);

            fwrite($socket, "QUIT\r\n");

            return new SmtpProbeResult(
                reachable: true,
                tlsSupported: false !== stripos($ehloResponse, 'STARTTLS'),
            );
        } finally {
            fclose($socket);
        }
    }

    /**
     * fsockopen() builds `tcp://<hostname>:<port>`, so a bare IPv6 literal
     * turns into an address the parser cannot split — the colons in the literal
     * are indistinguishable from the port separator, and the connection never
     * reaches the host. Measured: `fsockopen('::1', 25)` fails with
     * "getaddrinfo for  failed" (note the empty host) while `fsockopen('[::1]', 25)`
     * genuinely reaches loopback and is refused.
     *
     * That mattered beyond a missing tick: an IPv6-only mail host read as
     * unreachable, and MxChecker's copy for "nothing answered" blames our own
     * egress filtering. We were telling users their perfectly deliverable mail
     * server was fine but our network was restricted, when in fact we had
     * dialled an address that does not exist.
     */
    private function dialAddress(string $ip): string
    {
        return str_contains($ip, ':') ? '['.$ip.']' : $ip;
    }

    /**
     * @return resource|null
     */
    private function dial(string $ip)
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($this->dialAddress($ip), 25, $errno, $errstr, self::CONNECT_TIMEOUT_SECONDS);

        if (false === $socket) {
            return null;
        }

        stream_set_timeout($socket, self::READ_TIMEOUT_SECONDS);

        return $socket;
    }

    /**
     * An SMTP reply is one or more lines sharing a status code: every line but
     * the last separates the code from its text with `-`, the last with a space.
     * Stopping after the first line is what made this probe indistinguishable
     * from a spambot. Postfix's postscreen opens with a partial `220-` banner,
     * withholds the final `220 ` line while it runs its deep protocol tests, and
     * logs any client that writes before that line arrives as PREGREET.
     *
     * Measured against a mailcow host on 2026-07-31: one scan was enough for a
     * ban, and because that CrowdSec instance feeds a fleet-wide LAPI, the block
     * applied to every service the probing machine talked to — including its own
     * database, which is how it was noticed.
     *
     * Reading to the end also fixes a plain correctness bug on hosts that simply
     * greet across several lines: the leftover greeting was read as the EHLO
     * response, so STARTTLS went unseen and the host was reported as not
     * supporting TLS.
     *
     * @param resource $socket
     */
    private function readReply($socket): string
    {
        $reply = '';

        while (false !== ($line = @fgets($socket, self::READ_BUFFER))) {
            $reply .= $line;

            if (!isset($line[3]) || '-' !== $line[3]) {
                break;
            }
        }

        return $reply;
    }
}
