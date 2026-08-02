<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\AiNotEnabledForPlan;
use App\Exceptions\AiQuotaExceeded;
use App\Repository\TeamRepository;
use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\Result\AnomalyExplanationResult;
use App\Services\Ai\Result\OnDemandExplanationResult;
use App\Services\Ai\Result\RemediationResult;
use App\Services\Ai\Result\SenderLabelResult;
use App\Services\Ai\Result\WeeklyDigestResult;
use App\Services\Stripe\PlanEnforcement;
use App\Services\Stripe\PlanLimits;
use App\Value\SubscriptionPlan;
use Ramsey\Uuid\UuidInterface;

/**
 * Decorates any `AiInsightsService` with plan + quota gating. All five
 * operations refuse when the team's plan has no AI; `explainReport` also
 * enforces the monthly on-demand quota and increments the counter on
 * success.
 *
 * The four automatic features (digest, anomaly, remediation, sender label)
 * are gated by plan only — they fire on triggers (cron / event), not on
 * user clicks, so quota would be the wrong cost lever. `explainReport` is
 * the user-initiated button, hence the per-call quota.
 *
 * The plan gate answers "is this team entitled to AI". It cannot answer "is
 * there an AI to reach", and those came apart: DEC-057 describes an unset
 * ANTHROPIC_API_KEY as the switch that keeps AI unwired, but only
 * StripePriceResolver ever read it — that gate decides what can be *bought*.
 * Execution asked the plan alone, so any deployment whose plan says yes and
 * whose key is empty called Anthropic with no credentials and took a 401 per
 * insight. `Unlimited` makes that the default rather than the corner case:
 * it is the staff-grant tier, hasAi() is true for it unconditionally, and it
 * is what a self-hoster is told to set. Measured on one such instance: 151
 * failed calls in thirty minutes, two messages dead in the failure transport.
 *
 * Remediation and sender labelling made it worse by not gating on plan at
 * all — they fire per processed report, so the calls arrived whatever the
 * team was paying for.
 *
 * So the inner service is now chosen by whether AI is configured, which is
 * what this class's own wiring note always described: "only the inner
 * binding changes". Unconfigured falls back to the stub, whose honest
 * placeholder copy is exactly the pre-launch behaviour that binding had.
 */
final readonly class PlanGatedAiInsightsService implements AiInsightsService
{
    public function __construct(
        private AiInsightsService $inner,
        private TeamRepository $teams,
        private PlanEnforcement $enforcement,
        private PlanLimits $limits,
        private AiInsightsService $unconfigured,
        private bool $aiConfigured = false,
    ) {
    }

    public function generateWeeklyDigest(UuidInterface $teamId): WeeklyDigestResult
    {
        $this->assertPlanHasAi($teamId);

        return $this->reachable()->generateWeeklyDigest($teamId);
    }

    public function explainAnomaly(UuidInterface $reportId, UuidInterface $teamId): AnomalyExplanationResult
    {
        $this->assertPlanHasAi($teamId);

        return $this->reachable()->explainAnomaly($reportId, $teamId);
    }

    public function explainReport(UuidInterface $reportId, UuidInterface $teamId): OnDemandExplanationResult
    {
        $plan = $this->assertPlanHasAi($teamId);

        if (!$this->enforcement->canUseOnDemandAi($teamId->toString(), $plan)) {
            \Sentry\addBreadcrumb(\Sentry\Breadcrumb::fromArray([
                'category' => 'plan.ai_quota_exceeded',
                'level' => 'warning',
                'data' => [
                    'team_id' => $teamId->toString(),
                    'plan' => $plan->value,
                ],
            ]));

            throw new AiQuotaExceeded(used: $this->enforcement->getOnDemandAiUsage($teamId->toString()), limit: $this->limits->getOnDemandAiQuota($plan));
        }

        $result = $this->reachable()->explainReport($reportId, $teamId);

        $this->enforcement->incrementOnDemandAiUsage($teamId->toString());

        return $result;
    }

    public function generateRemediationGuidance(UuidInterface $domainId, DnsCheckFailure $failure): RemediationResult
    {
        // Remediation guidance fires on a DNS-check trigger; the caller
        // resolves the domain's team and is responsible for skipping when
        // the plan has no AI. Stub passthrough — no quota involved.
        return $this->reachable()->generateRemediationGuidance($domainId, $failure);
    }

    public function labelSender(string $ip, string $domain): SenderLabelResult
    {
        // Smart sender labeling runs against newly observed IPs across all
        // AI-enabled teams. The caller decides whether to enqueue work for
        // a given team. No quota — Haiku is cheap.
        return $this->reachable()->labelSender($ip, $domain);
    }

    /**
     * The service that can actually answer. Entitlement is decided by the
     * plan; reachability is decided by whether a key was configured, and
     * these two are not the same question.
     */
    private function reachable(): AiInsightsService
    {
        return $this->aiConfigured ? $this->inner : $this->unconfigured;
    }

    private function assertPlanHasAi(UuidInterface $teamId): SubscriptionPlan
    {
        $plan = $this->teams->get($teamId)->getSubscriptionPlan();

        if (!$plan->hasAi()) {
            throw new AiNotEnabledForPlan($plan);
        }

        return $plan;
    }
}
