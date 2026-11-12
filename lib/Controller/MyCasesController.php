<?php

/**
 * Portaliq My Cases Controller
 *
 * "Mijn zaken" for the citizen: every case any contributing app attached to
 * this identity, including the ones attached before the person had ever
 * logged in. One route, because a citizen has one list of cases however many
 * apps run them.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Identity\MandateParties;
use OCA\Portaliq\Service\Identity\PortalMandateService;
use OCA\Portaliq\Service\Identity\PortalPartyTreeResolver;
use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\MandatedCaseReader;
use OCA\Portaliq\Service\PortalCaseListReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves the subject's own case list.
 *
 * @spec openspec/specs/portal-identity-space/spec.md
 */
class MyCasesController extends Controller implements PortalProtected {
	/**
	 * The `mandate` value that means "acting for yourself": your own cases,
	 * no mandate spent, while the mandates you hold are still named
	 * (cases-my-cases-page REQ-CMC-004). Without any value the first mandate
	 * held is the active one, as before.
	 */
	public const ACTING_FOR_SELF = MandatedCaseReader::ACTING_FOR_SELF;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalContributionRegistry $registry The contribution aggregator.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalCaseListReader $cases Merges every case collection.
	 * @param PortalMandateService $mandates The mandates the identity holds.
	 * @param PortalPartyTreeResolver $tree Resolves how far a mandate reaches.
	 * @param CaseTypeVisibility|null $caseTypes The case types the serving
	 *                                           portal hides
	 *                                           (operate-show-per-case-type).
	 *                                           Absent hides nothing.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalSessionService $session,
		private readonly PortalCaseListReader $cases,
		private readonly PortalMandateService $mandates,
		private readonly PortalPartyTreeResolver $tree,
		private readonly ?CaseTypeVisibility $caseTypes = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The subject's own cases, newest first.
	 *
	 * @return JSONResponse The case list, or 401 without a session.
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-you-choose-whom-you-act-for-req-cmc-004
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			// A pending account has no session, so it lands here: 401, and
			// nothing about the account is said either way.
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$run = new MyCasesRun(subject: $subject, aggregate: $this->registry->aggregateFor($subject));
		// The case types the portal this request is served from does not show
		// (operate-show-per-case-type REQ-OSC-002).
		$run->hidden = ($this->caseTypes?->hiddenForRequest(request: $this->request, subject: $subject) ?? []);
		$run->rows   = $this->cases->listCases(subject: $subject, aggregate: $run->aggregate, hiddenCaseTypes: $run->hidden);

		return $this->casesAnswer(run: $run);
	}//end index()

	/**
	 * The listing with the organisation half (REQ-PIOC-002, REQ-PIOC-008): the
	 * mandates the identity holds, the one it is acting under, and that
	 * one's cases.
	 *
	 * @param MyCasesRun $run The listing, with the subject's own cases.
	 *
	 * @return JSONResponse
	 */
	private function casesAnswer(MyCasesRun $run): JSONResponse {
		$run->held       = $this->heldMandates(subject: $run->subject);
		$describedActive = $this->actingMandate(run: $run);
		if ($describedActive instanceof JSONResponse) {
			return $describedActive;
		}

		return new JSONResponse([
			'cases' => $run->rows,
			'mandates' => array_map(fn (array $mandate): array => $this->mandates->describe(mandate: $mandate), $run->held),
			'activeMandate' => $describedActive,
		]);
	}//end casesAnswer()

	/**
	 * The mandates the identity holds.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function heldMandates(array $subject): array {
		return $this->mandates->mandatesFor(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			holders: (new MandateParties())->holdersOf(subject: $subject)
		);
	}//end heldMandates()

	/**
	 * The described mandate the request acts under, with its cases merged
	 * into the listing; null when it acts for itself or names no mandate it
	 * holds, the 409 when the group is past the bound.
	 *
	 * @param MyCasesRun $run The listing, with the mandates held.
	 *
	 * @return array<string, mixed>|JSONResponse|null
	 */
	private function actingMandate(MyCasesRun $run): array|JSONResponse|null {
		$active = $this->requestedMandate(held: $run->held);
		if ($active === null) {
			return null;
		}

		return $this->withMandatedCases(run: $run, active: $active);
	}//end actingMandate()

	/**
	 * The held mandate the request names, or null. Naming a mandate that is
	 * not theirs selects nothing, rather than falling back to one that is.
	 *
	 * @param array<int, array<string, mixed>> $held The mandates the identity holds.
	 *
	 * @return array<string, mixed>|null
	 */
	private function requestedMandate(array $held): ?array {
		$requested = (string)$this->request->getParam('mandate', '');
		if ($requested === self::ACTING_FOR_SELF) {
			return null;
		}

		return $this->mandates->activeMandate(mandates: $held, mandateId: $requested);
	}//end requestedMandate()

	/**
	 * Merge the active mandate's cases into the listing and describe it with
	 * every entity it reaches.
	 *
	 * How far this mandate reaches is resolved from the party tree at
	 * request time. Past the bound the whole listing is refused: a partial
	 * list would read as "the group has no more cases".
	 *
	 * @param MyCasesRun           $run    The listing.
	 * @param array<string, mixed> $active The mandate acted under.
	 *
	 * @return array<string, mixed>|JSONResponse
	 */
	private function withMandatedCases(MyCasesRun $run, array $active): array|JSONResponse {
		$scope = $this->mandateScope(mandate: $active);
		if ($scope['refused'] === true) {
			return $this->groupTooLarge(scope: $scope);
		}

		$active['_entities'] = $scope['entities'];
		$run->rows = $this->merge(
			rows: $run->rows,
			extra: $this->cases->listMandatedCases(subject: $run->subject, aggregate: $run->aggregate, mandates: [$active], hiddenCaseTypes: $run->hidden)
		);

		$describedActive = $this->mandates->describe(mandate: $active);
		// The switcher offers every entity the mandate reaches, which for
		// a flat mandate is the one it names (REQ-PTV-006).
		$describedActive['entities'] = $scope['entities'];
		return $describedActive;
	}//end withMandatedCases()

	/**
	 * The entities a mandate reaches, from the party it is held for, down
	 * the tree when its reach says so.
	 *
	 * @param array<string, mixed> $mandate The mandate.
	 *
	 * @return array<string, mixed>
	 */
	private function mandateScope(array $mandate): array {
		return $this->tree->entitiesFor(
			root: $this->partyOf(mandate: $mandate),
			reachesDown: ($this->mandates->reachOf(mandate: $mandate) === PortalMandateService::REACH_TREE)
		);
	}//end mandateScope()

	/**
	 * The refusal of a group past the bound.
	 *
	 * @param array<string, mixed> $scope The refused scope, with its bound.
	 *
	 * @return JSONResponse
	 */
	private function groupTooLarge(array $scope): JSONResponse {
		return new JSONResponse(
			[
				'error' => 'group_too_large',
				'bound' => $scope['bound'],
			],
			Http::STATUS_CONFLICT
		);
	}//end groupTooLarge()

	/**
	 * The party a mandate is held for: the entity it names, or its tenant.
	 *
	 * @param array<string, mixed> $mandate The mandate.
	 *
	 * @return string
	 */
	private function partyOf(array $mandate): string {
		$described = $this->mandates->describe(mandate: $mandate);
		if ($described['onBehalfOf'] !== '') {
			return $described['onBehalfOf'];
		}

		return $described['organisation'];
	}//end partyOf()

	/**
	 * Merge the mandated rows into the subject's own, without listing a case
	 * twice when it is both theirs and their organisation's.
	 *
	 * @param array<int, array<string, mixed>> $rows The subject's own cases.
	 * @param array<int, array<string, mixed>> $extra The mandated cases.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function merge(array $rows, array $extra): array {
		$seen = [];
		foreach ($rows as $row) {
			$seen[$this->rowKey(row: $row)] = true;
		}

		foreach ($extra as $row) {
			$key = $this->rowKey(row: $row);
			if (isset($seen[$key]) === true) {
				continue;
			}

			$seen[$key] = true;
			$rows[] = $row;
		}

		return $rows;
	}//end merge()

	/**
	 * A row's identity for de-duplication: its schema and its own id.
	 *
	 * @param array<string, mixed> $row The case row.
	 *
	 * @return string
	 */
	private function rowKey(array $row): string {
		$self = ($row['@self'] ?? null);
		$id = ($row['id'] ?? $row['uuid'] ?? null);
		if ($id === null && is_array($self) === true) {
			$id = ($self['id'] ?? $self['uuid'] ?? null);
		}

		$source = (array)($row['_source'] ?? []);

		return (string)($source['schema'] ?? '') . ':' . (string)$id;
	}//end rowKey()
}//end class
