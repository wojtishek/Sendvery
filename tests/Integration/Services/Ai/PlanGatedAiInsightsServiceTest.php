<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Ai;

use App\Entity\Team;
use App\Exceptions\AiNotEnabledForPlan;
use App\Exceptions\AiQuotaExceeded;
use App\Exceptions\ReportNotAnalyzable;
use App\Repository\TeamRepository;
use App\Services\Ai\AiInsightsService;
use App\Services\Ai\CachingAiInsightsService;
use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\PlanGatedAiInsightsService;
use App\Services\Ai\Result\AnomalyExplanationResult;
use App\Services\Ai\Result\OnDemandExplanationResult;
use App\Services\Ai\Result\RemediationResult;
use App\Services\Ai\Result\SenderLabelResult;
use App\Services\Ai\Result\WeeklyDigestResult;
use App\Services\Ai\StubAiInsightsService;
use App\Services\Stripe\PlanEnforcement;
use App\Services\Stripe\PlanLimits;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class PlanGatedAiInsightsServiceTest extends IntegrationTestCase
{
    public function testInterfaceResolvesToTheCachingDecoratorSoCacheHitsBypassTheGate(): void
    {
        $service = $this->getService(AiInsightsService::class);

        // Caching is the outermost decorator: a cache hit returns before the
        // plan/quota gate is entered, so re-views cost nothing and burn no quota.
        self::assertInstanceOf(CachingAiInsightsService::class, $service);
    }

    public function testAnUnconfiguredKeyNeverReachesTheProvider(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeam($em, SubscriptionPlan::Unlimited);

        self::assertSame(SubscriptionPlan::Unlimited, $team->getSubscriptionPlan());

        $result = $this->withoutAnthropicKey()->labelSender('203.0.113.10', 'example.com');

        // labelSender and generateRemediationGuidance carry no plan gate at all
        // — they fire per processed report — so an unconfigured instance called
        // Anthropic once per report and collected a 401 each time. The spy below
        // throws if it is reached, which is the whole assertion.
        self::assertNotSame('', $result->label);
    }

    public function testAnUnconfiguredKeyDoesNotLoosenThePlanGate(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = $this->createTeam($em, SubscriptionPlan::Free);

        $this->expectException(AiNotEnabledForPlan::class);

        // Entitlement and reachability are separate questions, and answering the
        // second must not answer the first: a team that never bought AI is still
        // refused, it just is not refused by a 401 from the provider.
        $this->withoutAnthropicKey()->generateWeeklyDigest($team->id);
    }

    /**
     * The service as a deployment with an empty ANTHROPIC_API_KEY builds it:
     * the real client in place, but unreachable, and a spy standing in for it
     * that fails the test if anything gets that far.
     */
    private function withoutAnthropicKey(): PlanGatedAiInsightsService
    {
        $neverCalled = new class () implements AiInsightsService {
            public function generateWeeklyDigest(UuidInterface $teamId): WeeklyDigestResult
            {
                throw new \LogicException('Anthropic was called with no API key configured.');
            }

            public function explainAnomaly(UuidInterface $reportId, UuidInterface $teamId): AnomalyExplanationResult
            {
                throw new \LogicException('Anthropic was called with no API key configured.');
            }

            public function explainReport(UuidInterface $reportId, UuidInterface $teamId): OnDemandExplanationResult
            {
                throw new \LogicException('Anthropic was called with no API key configured.');
            }

            public function generateRemediationGuidance(UuidInterface $domainId, DnsCheckFailure $failure): RemediationResult
            {
                throw new \LogicException('Anthropic was called with no API key configured.');
            }

            public function labelSender(string $ip, string $domain): SenderLabelResult
            {
                throw new \LogicException('Anthropic was called with no API key configured.');
            }
        };

        return new PlanGatedAiInsightsService(
            inner: $neverCalled,
            teams: $this->getService(TeamRepository::class),
            enforcement: $this->getService(PlanEnforcement::class),
            limits: $this->getService(PlanLimits::class),
            unconfigured: new StubAiInsightsService(),
            aiConfigured: false,
        );
    }

    public function testExplainReportFailsWhenPlanHasNoAi(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::Personal);

        $this->expectException(AiNotEnabledForPlan::class);

        $gated->explainReport(Uuid::uuid7(), $team->id);
    }

    public function testWeeklyDigestFailsWhenPlanHasNoAi(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::Free);

        $this->expectException(AiNotEnabledForPlan::class);

        $gated->generateWeeklyDigest($team->id);
    }

    public function testExplainAnomalyFailsWhenPlanHasNoAi(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::Pro);

        $this->expectException(AiNotEnabledForPlan::class);

        $gated->explainAnomaly(Uuid::uuid7(), $team->id);
    }

    public function testExplainReportDoesNotChargeQuotaWhenTheReportCannotBeAnalyzed(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::PersonalAi);
        $teamId = $team->id->toString();

        self::assertSame(0, $enforcement->getOnDemandAiUsage($teamId));

        try {
            $gated->explainReport(Uuid::uuid7(), $team->id);
            self::fail('Expected ReportNotAnalyzable.');
        } catch (ReportNotAnalyzable) {
            // expected — the report doesn't exist for this team.
        }

        // Quota is charged only on a real generation, never when analysis fails.
        self::assertSame(0, $enforcement->getOnDemandAiUsage($teamId));
    }

    public function testExplainReportThrowsQuotaExceededAtLimit(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $enforcement = $this->getService(PlanEnforcement::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::PersonalAi);
        $teamId = $team->id->toString();

        // PersonalAi quota is 50/month. Burn the quota.
        for ($i = 0; $i < 50; ++$i) {
            $enforcement->incrementOnDemandAiUsage($teamId);
        }

        try {
            $gated->explainReport(Uuid::uuid7(), $team->id);
            self::fail('Expected AiQuotaExceeded to be thrown.');
        } catch (AiQuotaExceeded $exception) {
            self::assertSame(50, $exception->used);
            self::assertSame(50, $exception->limit);
        }
    }

    public function testWeeklyDigestSucceedsOnAiPlan(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::ProAi);

        $result = $gated->generateWeeklyDigest($team->id);

        self::assertNotSame('', $result->summaryMarkdown);
    }

    public function testExplainAnomalyPassesTheGateThenFailsAnalysisForAnUnknownReport(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $team = $this->createTeam($em, SubscriptionPlan::BusinessAi);

        // Plan gate passes (BusinessAi has AI); the inner orchestrator then can't
        // analyze a non-existent report and throws.
        $this->expectException(ReportNotAnalyzable::class);

        $gated->explainAnomaly(Uuid::uuid7(), $team->id);
    }

    public function testRemediationGuidancePassesThroughWithoutQuotaCheck(): void
    {
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $result = $gated->generateRemediationGuidance(
            Uuid::uuid7(),
            new DnsCheckFailure('SPF', 'example.com', 'too many lookups'),
        );

        self::assertNotSame('', $result->instructionsMarkdown);
    }

    public function testLabelSenderPassesThroughWithoutQuotaCheck(): void
    {
        $gated = $this->getService(PlanGatedAiInsightsService::class);

        $result = $gated->labelSender('192.0.2.1', 'example.com');

        self::assertNotSame('', $result->label);
    }

    private function createTeam(EntityManagerInterface $em, SubscriptionPlan $plan): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'AI Test Team',
            slug: 'ai-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $plan->value,
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        return $team;
    }
}
