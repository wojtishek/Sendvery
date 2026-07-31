<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

return App::config([
    'services' => [
        'App\\' => [
            'resource' => '../src/',
            'exclude' => [
                '../src/DependencyInjection/',
                '../src/Entity/',
                '../src/Kernel.php',
            ],
        ],
        PdoSessionHandler::class => [
            'arguments' => ['%env(DATABASE_URL)%'],
        ],
        'Spatie\Dns\Dns' => [
            'autoconfigure' => true,
        ],
        'App\Services\Dns\SmtpProbe' => [
            'alias' => 'App\Services\Dns\SocketSmtpProbe',
        ],
        'App\Services\Dns\ReverseDnsResolver' => [
            'alias' => 'App\Services\Dns\SystemReverseDnsResolver',
        ],
        'App\Services\Dns\AsnResolver' => [
            'alias' => 'App\Services\Dns\SystemAsnResolver',
        ],
        'App\Services\Dns\DnswlResolver' => [
            'alias' => 'App\Services\Dns\SystemDnswlResolver',
        ],
        'SPFLib\Decoder' => [
            'autoconfigure' => true,
        ],
        'SPFLib\SemanticValidator' => [
            'autoconfigure' => true,
        ],
        'App\Services\Mail\MailClient' => [
            'alias' => 'App\Services\Mail\ImapMailClient',
        ],
        'App\Services\Mailbox\MailboxConnectionTester' => [
            'alias' => 'App\Services\Mailbox\ImapMailboxConnectionTester',
        ],
        'App\Services\Reports\CentralInboxClient' => [
            'alias' => 'App\Services\Reports\ImapCentralInboxClient',
        ],
        'App\Services\Github\GithubApiClient' => [
            'alias' => 'App\Services\Github\FileGetContentsGithubApiClient',
        ],
        'App\Services\Dns\DnsRecordPublisher' => [
            'alias' => 'App\Services\Dns\CloudflareDnsClient',
        ],
        'App\Services\Stripe\SubscriptionManager' => [
            'arguments' => [
                '$defaultUri' => '%env(DEFAULT_URI)%',
            ],
        ],
        'App\Services\Stripe\StripePriceResolver' => [
            'arguments' => [
                // DEC-057: AI variants are gated on the presence of an
                // ANTHROPIC_API_KEY. When the key is set the real AI service
                // is wired and AI plans become purchasable; when it's not,
                // StripePriceResolver throws AiNotYetPurchasable on AI plan
                // lookups (caught by UpgradePlanController).
                '$aiPurchasable' => '%env(bool:ANTHROPIC_API_KEY)%',
            ],
        ],
        // AI Insights wiring (DEC-057): interface → Caching → PlanGated → Anthropic.
        // Caching sits OUTSIDE the gate so cache hits cost nothing and burn no
        // on-demand quota; PlanGated enforces plan + quota; AnthropicAiInsightsService
        // is the real implementation. AnthropicClient autowires HttpClientInterface
        // and the ANTHROPIC_* env vars (see .env).
        'App\Services\Ai\AiInsightsService' => [
            'alias' => 'App\Services\Ai\CachingAiInsightsService',
        ],
        'App\Services\Ai\CachingAiInsightsService' => [
            'arguments' => [
                '$inner' => '@App\Services\Ai\PlanGatedAiInsightsService',
            ],
        ],
        'App\Services\Ai\PlanGatedAiInsightsService' => [
            'arguments' => [
                '$inner' => '@App\Services\Ai\AnthropicAiInsightsService',
            ],
        ],
        'App\Controller\Webhook\StripeWebhookController' => [
            'arguments' => [
                '$stripeWebhookSecret' => '%env(STRIPE_WEBHOOK_SECRET)%',
            ],
        ],
        // The browser suite's login bypass. `.env` leaves this EMPTY, which is
        // what makes the endpoint inert everywhere it has not been deliberately
        // switched on — see App\Controller\TestLoginController for the other two
        // gates.
        'App\Controller\TestLoginController' => [
            'arguments' => [
                '$testLoginSecret' => '%env(SENDVERY_TEST_LOGIN_SECRET)%',
            ],
        ],
        'App\Services\Sentry\SentryTracesSampler' => [
            'arguments' => [
                '$profilingSecret' => '%env(SENTRY_PROFILING_SECRET)%',
                '$defaultTracesSampleRate' => '%env(float:SENTRY_TRACES_SAMPLE_RATE)%',
            ],
        ],
        'sentry.traces_sampler' => [
            'class' => Closure::class,
            'factory' => ['@App\Services\Sentry\SentryTracesSampler', '__invoke'],
        ],
    ],
    'when@test' => [
        'services' => [
            // No live DNS in tests: the four DNS checkers ask Spatie\Dns\Dns,
            // which by default queries the system resolver. Aliasing it to a
            // do-nothing fake makes integration tests fast and deterministic.
            // Tests that need positive DNS data use the StubDns helper directly.
            'Spatie\Dns\Dns' => [
                'alias' => 'App\Services\Dns\FakeDns',
            ],
            'App\Services\Dns\FakeDns' => [
                'public' => true,
            ],
            // SmtpProbe: production opens a real TCP connection to port 25.
            // Tests must never do that — alias to the in-memory fake.
            'App\Services\Dns\SmtpProbe' => [
                'alias' => 'App\Services\Dns\FakeSmtpProbe',
            ],
            'App\Services\Dns\FakeSmtpProbe' => [
                'public' => true,
            ],
            // Reverse DNS: production calls the system resolver. Tests must
            // never do that (DEC-059 requires rDNS behind a faked interface),
            // so the interface resolves to the scriptable in-memory fake.
            'App\Services\Dns\ReverseDnsResolver' => [
                'alias' => 'App\Services\Dns\FakeReverseDnsResolver',
                'public' => true,
            ],
            'App\Services\Dns\FakeReverseDnsResolver' => [
                'public' => true,
            ],
            // Same rule for the AS lookup: production asks Team Cymru over DNS,
            // and no test may. Scripted per address by the in-memory fake.
            'App\Services\Dns\AsnResolver' => [
                'alias' => 'App\Services\Dns\FakeAsnResolver',
                'public' => true,
            ],
            'App\Services\Dns\FakeAsnResolver' => [
                'public' => true,
            ],
            // And for the RFC 8904 whitelist: production queries dnswl.org over
            // DNS, and no test may.
            'App\Services\Dns\DnswlResolver' => [
                'alias' => 'App\Services\Dns\FakeDnswlResolver',
                'public' => true,
            ],
            'App\Services\Dns\FakeDnswlResolver' => [
                'public' => true,
            ],
            // SPFLib uses its own DNS resolver, outside the App namespace and
            // outside symfony/phpunit-bridge's dns-mock reach. Inject our fake
            // resolver into the Decoder so SPF lookups stay in-process.
            'SPFLib\Decoder' => [
                'arguments' => [
                    '$dnsResolver' => '@App\Services\Dns\FakeSpfResolver',
                ],
            ],
            'App\Services\IdentityProvider' => [
                'public' => true,
            ],
            'App\Services\TeamContext' => [
                'public' => true,
            ],
            'App\Repository\TeamRepository' => [
                'public' => true,
            ],
            'App\Repository\UserRepository' => [
                'public' => true,
            ],
            'App\Repository\TeamMembershipRepository' => [
                'public' => true,
            ],
            'App\Query\GetUserTeams' => [
                'public' => true,
            ],
            'App\Services\Dns\SpfChecker' => [
                'public' => true,
            ],
            'App\Services\Dns\DkimChecker' => [
                'public' => true,
            ],
            'App\Services\Dns\DmarcChecker' => [
                'public' => true,
            ],
            'App\Services\Dns\MxChecker' => [
                'public' => true,
            ],
            'App\Services\Dns\EmailAuthChecker' => [
                'public' => true,
            ],
            'App\Services\Dns\DomainHealthScorer' => [
                'public' => true,
            ],
            'App\Repository\BetaSignupRepository' => [
                'public' => true,
            ],
            'App\MessageHandler\RegisterBetaSignupHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\NotifyMeAboutToolHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\ProcessDmarcReportHandler' => [
                'public' => true,
            ],
            'App\Repository\MonitoredDomainRepository' => [
                'public' => true,
            ],
            'App\Repository\DmarcReportRepository' => [
                'public' => true,
            ],
            'App\Query\GetDomainOverview' => [
                'public' => true,
            ],
            'App\Query\GetReportDetail' => [
                'public' => true,
            ],
            'App\Services\Dmarc\DmarcXmlParser' => [
                'public' => true,
            ],
            'App\Services\Dmarc\ReportAttachmentExtractor' => [
                'public' => true,
            ],
            'App\Services\CredentialEncryptor' => [
                'public' => true,
            ],
            'App\Repository\MailboxConnectionRepository' => [
                'public' => true,
            ],
            'App\MessageHandler\ConnectMailboxHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\PollMailboxHandler' => [
                'public' => true,
            ],
            'App\Services\Mail\FakeMailClient' => [
                'public' => true,
            ],
            'App\Services\Mail\MailClient' => [
                'alias' => 'App\Services\Mail\FakeMailClient',
                'public' => true,
            ],
            'App\Services\Auth\SignInAllowlist' => [
                'public' => true,
            ],
            'App\Services\Mail\ImapMailClient' => [
                'public' => true,
            ],
            'App\Services\Mailbox\FakeMailboxConnectionTester' => [
                'public' => true,
            ],
            'App\Services\Mailbox\ImapMailboxConnectionTester' => [
                'public' => true,
            ],
            'App\Services\Mailbox\MailboxConnectionTester' => [
                'alias' => 'App\Services\Mailbox\FakeMailboxConnectionTester',
                'public' => true,
            ],
            'App\Services\Reports\FakeCentralInboxClient' => [
                'public' => true,
            ],
            'App\Services\Reports\CentralInboxClient' => [
                'alias' => 'App\Services\Reports\FakeCentralInboxClient',
                'public' => true,
            ],
            'App\Services\Reports\CentralInboxConfig' => [
                'public' => true,
            ],
            'App\Services\Reports\ReportEmailIngestor' => [
                'public' => true,
            ],
            'App\Repository\ReceivedReportEmailRepository' => [
                'public' => true,
            ],
            'App\MessageHandler\PollReportsInboxHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\ProcessReceivedReportEmailHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\ReleaseQuarantinedReportsForDomainHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\ReleaseQuarantinedReportsWhenDomainVerified' => [
                'public' => true,
            ],
            'App\MessageHandler\ReleaseQuarantinedReportsForTeamHandler' => [
                'public' => true,
            ],
            'App\Query\GetReleasableQuarantinedReports' => [
                'public' => true,
            ],
            'App\Services\Reports\DmarcReportRouter' => [
                'public' => true,
            ],
            'App\Services\Reports\RawEmailMimeParser' => [
                'public' => true,
            ],
            'App\Repository\QuarantinedDmarcReportRepository' => [
                'public' => true,
            ],
            'App\Repository\TeamInvitationRepository' => [
                'public' => true,
            ],
            'App\MessageHandler\InviteTeammateHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\AcceptTeamInvitationHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\RevokeTeamInvitationHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\ResendTeamInvitationHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\RemoveTeamMemberHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\TransferTeamOwnershipHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\SendTeamInvitationEmailHandler' => [
                'public' => true,
            ],
            'App\Query\GetDashboardStats' => [
                'public' => true,
            ],
            'App\Query\GetDomainDetail' => [
                'public' => true,
            ],
            'App\Query\GetDomainVerificationStatus' => [
                'public' => true,
            ],
            'App\Query\GetTopSendersForDomain' => [
                'public' => true,
            ],
            'App\Query\GetDomainPassRateTrend' => [
                'public' => true,
            ],
            'App\Query\GetAllReports' => [
                'public' => true,
            ],
            'App\Query\GetReporterOrgs' => [
                'public' => true,
            ],
            'App\Services\DashboardContext' => [
                'public' => true,
            ],
            'App\MessageHandler\AddDomainHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\PublishAuthorizationRecordWhenDomainAdded' => [
                'public' => true,
            ],
            'App\Repository\MagicLinkTokenRepository' => [
                'public' => true,
            ],
            'App\MessageHandler\RequestMagicLinkHandler' => [
                'public' => true,
            ],
            'App\Services\OnboardingTracker' => [
                'public' => true,
            ],
            'App\Security\MagicLinkAuthenticator' => [
                'public' => true,
            ],
            'App\Services\Stripe\PlanEnforcement' => [
                'public' => true,
            ],
            'App\Services\Stripe\PlanLimits' => [
                'public' => true,
            ],
            'App\MessageHandler\UpgradeTeamPlanHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\DowngradeTeamPlanHandler' => [
                'public' => true,
            ],
            'App\Query\GetBillingOverview' => [
                'public' => true,
            ],
            'App\Query\GetTeamPlan' => [
                'public' => true,
            ],
            'App\Services\SenderDiscovery' => [
                'public' => true,
            ],
            'App\Services\OrganizationMapper' => [
                'public' => true,
            ],
            'App\Services\SenderIdentityResolver' => [
                'public' => true,
            ],
            'App\Services\SenderRoleClassifier' => [
                'public' => true,
            ],
            'App\Services\RegistrableDomainExtractor' => [
                'public' => true,
            ],
            'App\Services\ForwarderRegistry' => [
                'public' => true,
            ],
            'App\Repository\SenderIdentityRepository' => [
                'public' => true,
            ],
            'App\Services\BlacklistChecker' => [
                'public' => true,
            ],
            'App\Services\PdfReportGenerator' => [
                'public' => true,
            ],
            'App\Repository\KnownSenderRepository' => [
                'public' => true,
            ],
            'App\Query\GetSenderInventory' => [
                'public' => true,
            ],
            'App\Query\GetBlacklistStatus' => [
                'public' => true,
            ],
            'App\Query\GetDomainHealthHistory' => [
                'public' => true,
            ],
            'App\Query\GetDomainReportData' => [
                'public' => true,
            ],
            'App\MessageHandler\UpdateSenderInventoryOnReport' => [
                'public' => true,
            ],
            'App\MessageHandler\CheckBlacklistHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\MarkSenderAuthorizedHandler' => [
                'public' => true,
            ],
            'App\Services\Ai\StubAiInsightsService' => [
                'public' => true,
            ],
            // .env.test sets ANTHROPIC_API_KEY, so the real chain is wired in
            // tests too — but AnthropicClient gets a MockHttpClient, so no test
            // ever reaches the network (the "tests never hit a real API" rule).
            'App\Tests\TestSupport\AnthropicMockHttpClient' => [
                'public' => true,
            ],
            'App\Services\Ai\Client\AnthropicClient' => [
                'public' => true,
                'arguments' => [
                    '$httpClient' => '@App\Tests\TestSupport\AnthropicMockHttpClient',
                ],
            ],
            'App\Services\Ai\AnthropicAiInsightsService' => [
                'public' => true,
            ],
            'App\Services\Ai\PlanGatedAiInsightsService' => [
                'public' => true,
                'arguments' => [
                    '$inner' => '@App\Services\Ai\AnthropicAiInsightsService',
                ],
            ],
            'App\Services\Ai\CachingAiInsightsService' => [
                'public' => true,
                'arguments' => [
                    '$inner' => '@App\Services\Ai\PlanGatedAiInsightsService',
                ],
            ],
            'App\Services\Ai\AiInsightsService' => [
                'alias' => 'App\Services\Ai\CachingAiInsightsService',
                'public' => true,
            ],
            'App\Repository\AiInsightRepository' => [
                'public' => true,
            ],
            'App\MessageHandler\AlertOnFailureSpike' => [
                'public' => true,
            ],
            'App\MessageHandler\GenerateAnomalyInsightWhenSpikeDetected' => [
                'public' => true,
            ],
            'App\MessageHandler\GenerateRemediationInsightWhenDnsCheckFails' => [
                'public' => true,
            ],
            'App\MessageHandler\GenerateRemediationInsightHandler' => [
                'public' => true,
            ],
            'App\Command\ResetMonthlyUsageCountersCommand' => [
                'public' => true,
            ],
            'App\Command\PurgeOldDmarcReportsCommand' => [
                'public' => true,
            ],
            'App\Services\Stripe\SubscriptionManager' => [
                'public' => true,
                'arguments' => [
                    '$defaultUri' => '%env(DEFAULT_URI)%',
                ],
            ],
            'App\Command\WarnApproachingPlanLimitsCommand' => [
                'public' => true,
            ],
            'App\Services\Github\FakeGithubApiClient' => [
                'public' => true,
            ],
            'App\Services\Github\GithubApiClient' => [
                'alias' => 'App\Services\Github\FakeGithubApiClient',
                'public' => true,
            ],
            'App\Services\Dns\FakeDnsRecordPublisher' => [
                'public' => true,
            ],
            'App\Services\Dns\DnsRecordPublisher' => [
                'alias' => 'App\Services\Dns\FakeDnsRecordPublisher',
                'public' => true,
            ],
            'App\Services\Dns\CloudflareDnsClient' => [
                'public' => true,
            ],
            'App\Twig\OpenSourceExtension' => [
                'public' => true,
            ],
            'App\Twig\GithubStatsExtension' => [
                'public' => true,
            ],
            'App\Twig\PlaceholdersExtension' => [
                'public' => true,
            ],
            'App\Services\OgImage\OgImageRenderer' => [
                'public' => true,
            ],
            'App\Services\OgImage\HealthOgImageContentResolver' => [
                'public' => true,
            ],
            // TASK-134: query-count regression net. The PSR-3 logger captures
            // every SQL statement DBAL prepares/executes; tests fetch it from
            // the container to assert the batch resolver issues ONE select
            // against `dns_check_result` regardless of the input size. Wired
            // through Doctrine's bundled `Logging\Middleware` so the recording
            // happens transparently for every connection — no per-test setup.
            'App\Tests\TestSupport\InMemoryQueryLogger' => [
                'public' => true,
            ],
            'Doctrine\DBAL\Logging\Middleware' => [
                'arguments' => ['@App\Tests\TestSupport\InMemoryQueryLogger'],
                'tags' => [
                    ['name' => 'doctrine.middleware'],
                ],
            ],
        ],
    ],
]);
