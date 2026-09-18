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
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Identity\PortalMandateService;
use OCA\Portaliq\Service\Identity\PortalPartyTreeResolver;
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
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */
class MyCasesController extends Controller implements PortalProtected {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalContributionRegistry $registry The contribution aggregator.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalCaseListReader $cases Merges every case collection.
	 * @param PortalMandateService $mandates The mandates the identity holds.
	 * @param PortalPartyTreeResolver $tree Resolves how far a mandate reaches.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalSessionService $session,
		private readonly PortalCaseListReader $cases,
		private readonly PortalMandateService $mandates,
		private readonly PortalPartyTreeResolver $tree,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The subject's own cases, newest first.
	 *
	 * @return JSONResponse The case list, or 401 without a session.
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
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

		$aggregate = $this->registry->aggregateFor($subject);
		$rows = $this->cases->listCases(subject: $subject, aggregate: $aggregate);

		// The organisation half (REQ-PIOC-002, REQ-PIOC-008): the mandates the
		// identity holds, the one it is acting under, and that one's cases.
		// Naming a mandate that is not theirs selects nothing, rather than
		// falling back to one that is.
		$held = $this->mandates->mandatesFor(subjectRef: (string)($subject['subjectRef'] ?? ''), organisation: (string)($subject['organisation'] ?? ''));
		$requested = (string)$this->request->getParam('mandate', '');
		$active = $this->mandates->activeMandate(mandates: $held, mandateId: $requested);

		$describedActive = null;
		if ($active !== null) {
			// How far this mandate reaches, resolved from the party tree at
			// request time. Past the bound the whole listing is refused: a
			// partial list would read as "the group has no more cases".
			$scope = $this->tree->entitiesFor(
				root: $this->partyOf(mandate: $active),
				reachesDown: ($this->mandates->reachOf(mandate: $active) === PortalMandateService::REACH_TREE)
			);
			if ($scope['refused'] === true) {
				return new JSONResponse(
					[
						'error' => 'group_too_large',
						'bound' => $scope['bound'],
					],
					Http::STATUS_CONFLICT
				);
			}

			$active['_entities'] = $scope['entities'];
			$rows = $this->merge(
				rows: $rows,
				extra: $this->cases->listMandatedCases(subject: $subject, aggregate: $aggregate, mandates: [$active])
			);

			$describedActive = $this->mandates->describe(mandate: $active);
			// The switcher offers every entity the mandate reaches, which for
			// a flat mandate is the one it names (REQ-PTV-006).
			$describedActive['entities'] = $scope['entities'];
		}

		return new JSONResponse([
			'cases' => $rows,
			'mandates' => array_map(fn (array $mandate): array => $this->mandates->describe(mandate: $mandate), $held),
			'activeMandate' => $describedActive,
		]);
	}//end index()

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
