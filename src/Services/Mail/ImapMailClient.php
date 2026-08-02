<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Entity\MailboxConnection;
use App\Services\CredentialEncryptor;
use App\Services\Mailbox\ImapMailboxConnectionTester;
use App\Services\Reports\ImapCentralInboxClient;
use App\Value\ConnectionTestResult;
use App\Value\MailAttachment;
use App\Value\MailboxConnectionErrorCode;
use App\Value\MailMessage;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Message;

final readonly class ImapMailClient implements MailClient
{
    private const array DMARC_SUBJECT_KEYWORDS = ['dmarc', 'report domain'];
    private const array DMARC_SENDER_PATTERNS = [
        'noreply-dmarc-support@google.com',
        'dmarcreport@microsoft.com',
        'dmarc@yahoo.com',
        'dmarc_agg@vali.email',
    ];
    private const array REPORT_EXTENSIONS = ['.zip', '.gz', '.gzip', '.xml'];

    public function __construct(
        private CredentialEncryptor $encryptor,
    ) {
    }

    /** @return iterable<MailMessage> */
    public function fetchDmarcReports(MailboxConnection $connection): iterable
    {
        $client = $this->createClient($connection);
        $client->connect();

        try {
            $folder = $client->getFolderByName('INBOX') ?? $client->getFolderByPath('INBOX');

            if (null === $folder) {
                return;
            }

            $messages = $folder->messages()->unseen()->get();

            foreach ($messages as $message) {
                assert($message instanceof Message);

                if (!$this->isDmarcReport($message)) {
                    continue;
                }

                $attachments = $this->extractReportAttachments($message);

                if ([] === $attachments) {
                    continue;
                }

                yield new MailMessage(
                    messageId: $message->getMessageId()->toString(),
                    subject: $message->getSubject()->toString(),
                    from: $message->getFrom()->first()->mail ?? '',
                    // Shared with the central inbox for the same reason
                    // rawEml is, and for a reason discovered the hard way:
                    // deriving it here instead is what made every BYO poll
                    // throw on the first message that carried a Date header.
                    date: ImapCentralInboxClient::receivedAt($message),
                    attachments: $attachments,
                    // Shared with the central inbox rather than reimplemented:
                    // Webklex's getRawBody() drops the headers, and a blob
                    // stored without them has no top-level Content-Type, so a
                    // later re-parse finds no MIME structure and no attachments.
                    // Getting that subtlety wrong twice is how the two ingestion
                    // paths drift.
                    rawEml: ImapCentralInboxClient::fullRawEml($message),
                );
            }
        } finally {
            $client->disconnect();
        }
    }

    public function markAsProcessed(MailboxConnection $connection, MailMessage $message): void
    {
        $client = $this->createClient($connection);
        $client->connect();

        try {
            $folder = $client->getFolderByName('INBOX') ?? $client->getFolderByPath('INBOX');

            if (null === $folder) {
                return;
            }

            $imapMessage = $folder->messages()
                ->whereMessageId($message->messageId)
                ->get()
                ->first();

            if (null !== $imapMessage) {
                assert($imapMessage instanceof Message);
                $imapMessage->setFlag('Seen');

                // Try to move to a "Processed" folder if it exists
                $processedFolder = $client->getFolderByName('Processed', soft_fail: true);
                if (null !== $processedFolder) {
                    $imapMessage->move($processedFolder->path);
                }
            }
        } finally {
            $client->disconnect();
        }
    }

    public function testConnection(MailboxConnection $connection): ConnectionTestResult
    {
        try {
            $client = $this->createClient($connection, timeout: 3);
            $client->connect();

            $folder = $client->getFolderByName('INBOX') ?? $client->getFolderByPath('INBOX');

            if (null === $folder) {
                $client->disconnect();

                return new ConnectionTestResult(
                    success: false,
                    error: 'INBOX folder not found.',
                    mailboxCount: 0,
                    errorCode: MailboxConnectionErrorCode::InboxNotFound,
                );
            }

            $status = $folder->status();
            $messageCount = $status['messages'] ?? 0;

            $client->disconnect();

            return new ConnectionTestResult(
                success: true,
                error: null,
                mailboxCount: (int) $messageCount,
            );
        } catch (ConnectionFailedException $e) {
            return new ConnectionTestResult(
                success: false,
                error: $e->getMessage(),
                mailboxCount: 0,
                errorCode: ImapMailboxConnectionTester::classifyError($e->getMessage()),
            );
        } catch (\Throwable $e) {
            return new ConnectionTestResult(
                success: false,
                error: $e->getMessage(),
                mailboxCount: 0,
                errorCode: ImapMailboxConnectionTester::classifyError($e->getMessage()),
            );
        }
    }

    private function createClient(MailboxConnection $connection, ?int $timeout = null): \Webklex\PHPIMAP\Client
    {
        $username = $this->encryptor->decrypt($connection->encryptedUsername);
        $password = $this->encryptor->decrypt($connection->encryptedPassword);

        $manager = new ClientManager();

        $config = [
            'host' => $connection->host,
            'port' => $connection->port,
            'encryption' => $connection->encryption->value,
            'validate_cert' => true,
            'username' => $username,
            'password' => $password,
            'protocol' => 'imap',
        ];

        if (null !== $timeout) {
            $config['timeout'] = $timeout;
        }

        return $manager->make($config);
    }

    private function isDmarcReport(Message $message): bool
    {
        $subject = strtolower($message->getSubject()->toString());
        foreach (self::DMARC_SUBJECT_KEYWORDS as $keyword) {
            if (str_contains($subject, $keyword)) {
                return true;
            }
        }

        $from = strtolower($message->getFrom()->first()->mail ?? '');
        foreach (self::DMARC_SENDER_PATTERNS as $pattern) {
            if ($from === $pattern) {
                return true;
            }
        }

        return false;
    }

    /** @return array<MailAttachment> */
    private function extractReportAttachments(Message $message): array
    {
        $attachments = [];

        foreach ($message->getAttachments() as $attachment) {
            assert($attachment instanceof \Webklex\PHPIMAP\Attachment);
            $filename = strtolower($attachment->getName());

            $isReport = false;
            foreach (self::REPORT_EXTENSIONS as $ext) {
                if (str_ends_with($filename, $ext)) {
                    $isReport = true;

                    break;
                }
            }

            if (!$isReport) {
                continue;
            }

            $attachments[] = new MailAttachment(
                filename: $attachment->getName(),
                content: $attachment->getContent(),
                mimeType: $attachment->getMimeType() ?? 'application/octet-stream',
            );
        }

        return $attachments;
    }
}
