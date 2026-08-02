<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Value\ConnectionTestResult;
use App\Value\Reports\CentralInboxFolder;
use App\Value\Reports\FetchedEnvelope;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * Production IMAP implementation. Holds a single Webklex client open across
 * fetch + move calls so a batch of 200 envelopes doesn't trigger 200 logins.
 *
 * Folder creation is best-effort: if Sendvery/Pending etc. don't exist, we
 * create them on first use. Seznam Email Profi supports nested folders via
 * standard IMAP namespacing.
 */
final class ImapCentralInboxClient implements CentralInboxClient
{
    private ?Client $client = null;

    public function __construct(
        private readonly CentralInboxConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<FetchedEnvelope> */
    public function fetchPending(): array
    {
        $client = $this->connect();
        $inbox = $this->openFolder($client, 'INBOX');

        $status = $inbox->status();
        $uidvalidity = isset($status['uidvalidity']) ? (int) $status['uidvalidity'] : null;

        $messages = $inbox->messages()
            ->unseen()
            ->limit($this->config->batchSize)
            ->get();

        $envelopes = [];

        foreach ($messages as $message) {
            assert($message instanceof Message);

            try {
                $rawEml = self::fullRawEml($message);
                $size = strlen($rawEml);
                if ($size > $this->config->maxMessageBytes) {
                    $this->logger->warning('Skipping oversized message in central inbox ({size} bytes).', [
                        'size' => $size,
                        'limit' => $this->config->maxMessageBytes,
                        'uid' => $message->getUid(),
                    ]);
                    $this->moveMessage($message, CentralInboxFolder::Failed);

                    continue;
                }

                $envelopes[] = $this->envelopeFromMessage($message, $uidvalidity, $rawEml);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to read message from central inbox: {error}', [
                    'error' => $e->getMessage(),
                    'uid' => $message->getUid(),
                ]);
            }
        }

        return $envelopes;
    }

    public function markSeen(int $uid): void
    {
        $client = $this->connect();
        $inbox = $this->openFolder($client, 'INBOX');

        try {
            $inbox->messages()->getMessageByUid($uid)->setFlag('Seen');
        } catch (\Throwable $e) {
            // Worst case the message is re-fetched next poll and deduped by
            // the (source, message_id) constraint — but log it, a recurring
            // failure here means every poll re-downloads the whole INBOX.
            $this->logger->warning('Failed to flag INBOX UID {uid} as seen: {error}', [
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function moveProcessed(?int $uid, ?int $uidvalidity, string $messageId, CentralInboxFolder $destination): void
    {
        $client = $this->connect();
        $inbox = $this->openFolder($client, 'INBOX');

        if (null !== $uid && null !== $uidvalidity && $this->inboxUidvalidityMatches($inbox, $uidvalidity)) {
            try {
                $message = $inbox->messages()->getMessageByUid($uid);
                $this->moveMessage($message, $destination);

                return;
            } catch (\Throwable $e) {
                $this->logger->warning('INBOX UID {uid} fetch failed, falling back to Message-ID lookup: {error}', [
                    'uid' => $uid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $message = $inbox->messages()->whereMessageId($messageId)->get()->first();
        if (!$message instanceof Message) {
            $this->logger->warning('Cannot move processed message {msgId} to {folder}: not found in INBOX.', [
                'msgId' => $messageId,
                'folder' => $destination->name,
            ]);

            return;
        }

        $this->moveMessage($message, $destination);
    }

    public function close(): void
    {
        if (null === $this->client) {
            return;
        }

        try {
            $this->client->disconnect();
        } catch (\Throwable) {
            // Best effort. A failed disconnect just means the socket dies on its own.
        }

        $this->client = null;
    }

    public function testConnection(): ConnectionTestResult
    {
        try {
            $client = $this->connect();
            $inbox = $this->openFolder($client, 'INBOX');
            $status = $inbox->status();
            $count = isset($status['messages']) ? (int) $status['messages'] : 0;
            $this->close();

            return new ConnectionTestResult(success: true, error: null, mailboxCount: $count);
        } catch (ConnectionFailedException $e) {
            $this->close();

            return new ConnectionTestResult(success: false, error: $e->getMessage(), mailboxCount: 0);
        } catch (\Throwable $e) {
            $this->close();

            return new ConnectionTestResult(success: false, error: $e->getMessage(), mailboxCount: 0);
        }
    }

    private function connect(): Client
    {
        if (null !== $this->client && $this->client->isConnected()) {
            return $this->client;
        }

        $manager = new ClientManager();
        $client = $manager->make([
            'host' => $this->config->host,
            'port' => $this->config->port,
            'encryption' => $this->config->encryption->value,
            'validate_cert' => true,
            'username' => $this->config->username,
            'password' => $this->config->password,
            'protocol' => 'imap',
        ]);
        $client->connect();

        $this->client = $client;

        return $client;
    }

    private function openFolder(Client $client, string $path): Folder
    {
        $folder = $client->getFolderByPath($path);
        if (null === $folder) {
            throw new \RuntimeException(sprintf('IMAP folder "%s" not found.', $path));
        }

        return $folder;
    }

    private function moveMessage(Message $message, CentralInboxFolder $folder): void
    {
        $path = $this->config->folderPath($folder);
        $this->ensureFolderExists($path);

        try {
            $message->move($path);
        } catch (MessageHeaderFetchingException $e) {
            // Webklex sends IMAP MOVE first, then tries to re-fetch the message at the
            // destination using the pre-move UIDNEXT. If that UID doesn't line up
            // (Seznam Email Profi sometimes returns a stale UIDNEXT), the post-move
            // header fetch throws even though the MOVE itself already succeeded.
            // We discard the returned Message anyway, so log and move on.
            $this->logger->info('Post-move header fetch failed but MOVE succeeded: {error}', [
                'error' => $e->getMessage(),
                'uid' => $message->getUid(),
                'folder' => $path,
            ]);
        }
    }

    private function ensureFolderExists(string $path): void
    {
        $client = $this->connect();
        if (null !== $client->getFolderByPath($path)) {
            return;
        }

        try {
            $client->createFolder($path, expunge: false);
            $this->logger->info('Created IMAP folder {path} in central inbox.', ['path' => $path]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to create IMAP folder {path}: {error}', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function inboxUidvalidityMatches(Folder $inbox, int $uidvalidity): bool
    {
        $status = $inbox->status();

        return isset($status['uidvalidity']) && (int) $status['uidvalidity'] === $uidvalidity;
    }

    /**
     * Reassembles the complete RFC 822 message from a fetched Message.
     *
     * Webklex's getRawBody() returns ONLY the body section — no headers. A
     * blob persisted without headers has no top-level Content-Type, so a
     * later Message::fromString() finds no MIME structure and therefore no
     * attachments. Header + CRLFCRLF + body reproduces the original bytes.
     */
    public static function fullRawEml(Message $message): string
    {
        return $message->getHeader()?->raw."\r\n\r\n".$message->getRawBody();
    }

    /**
     * The received date, immutable, from a fetched Message.
     *
     * Webklex hands back whatever the Date header parsed to — Carbon in
     * practice — and Carbon::toDate() returns a mutable \DateTime. Both
     * ingestion paths store an immutable one, and this conversion was written
     * twice: the central inbox did it, the BYO poller did not, and every BYO
     * poll died with a TypeError on the first message carrying a Date header.
     *
     * The `?? new \DateTimeImmutable()` in the copy that failed reads like it
     * covers exactly this and does not — it fires only when the header is
     * absent, which is the one case that was never the problem.
     *
     * Shared for the same reason fullRawEml() is.
     */
    public static function receivedAt(Message $message): \DateTimeImmutable
    {
        $dateFirst = $message->getDate()->first();

        return is_object($dateFirst) && method_exists($dateFirst, 'toDate')
            ? \DateTimeImmutable::createFromInterface($dateFirst->toDate())
            : new \DateTimeImmutable();
    }

    private function envelopeFromMessage(Message $message, ?int $uidvalidity, string $rawEml): FetchedEnvelope
    {
        $messageIdHeader = $message->getMessageId()->toString();
        $fallbackUid = (string) $message->getUid();
        $messageId = '' !== $messageIdHeader
            ? $messageIdHeader
            : sprintf('<no-header-%s.%s@central.sendvery>', $uidvalidity ?? 0, $fallbackUid);

        $fromAttribute = $message->getFrom();
        $fromFirst = $fromAttribute->first();
        $from = is_object($fromFirst) && property_exists($fromFirst, 'mail') ? (string) $fromFirst->mail : '';

        $receivedAt = self::receivedAt($message);

        return new FetchedEnvelope(
            messageId: $messageId,
            fromAddress: $from,
            subject: $message->getSubject()->toString(),
            receivedAt: $receivedAt,
            rawEml: $rawEml,
            uid: (int) $message->getUid(),
            uidvalidity: $uidvalidity,
        );
    }
}
