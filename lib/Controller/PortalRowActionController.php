<?php

/**
 * Portaliq Portal Row Action Controller (contribution-pay-screen)
 *
 * Runs an endpoint action for ONE row the subject owns: a guardian pays one
 * school contribution, a signer signs one document. The browser names the row
 * only in the path. The portal reads that row under the collection's own scope
 * first, and the id that reaches the leaf app is the id of the row it read.
 *
 * The order is the design. The collection must be the subject's and must offer
 * the action on its rows; trust and the endpoint are re-checked; the row must
 * be read under the subject's scope; the row must match the action's
 * `rowWhen`. Only then is a body built, from the action's `fields` whitelist
 * and the server's own stamps, and forwarded. A raw client body is never
 * relayed on this route.
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
 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Contribution\RowActionResolver;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves the row-scoped endpoint action forward.
 *
 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalSessionService::trustSatisfies,
 * the one trust ordering every portal gate shares.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- one collaborator per step
 * of the forward (who, which row action, whose row, send, record) plus the
 * framework attributes every portal endpoint carries.
 */
class PortalRowActionController extends Controller implements PortalProtected {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalContributionRegistry $registry The subject's contributions.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalObjectReader $reader The scoped single-object read.
	 * @param PortalActionForwarder $forwarder The signed server-to-server forward.
	 * @param AuditTrailService $auditor Records the forward.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalSessionService $session,
		private readonly PortalObjectReader $reader,
		private readonly PortalActionForwarder $forwarder,
		private readonly AuditTrailService $auditor,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Forward an endpoint row action for one row the subject owns.
	 *
	 * `?collection=` picks one of several collections on the same schema, as
	 * on the object read. The leaf app's status and JSON body are relayed.
	 *
	 * Rate limit: a human clicks this, and a payment start creates real work
	 * at a provider per call, so it matches shillinq's own pay receiver.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 * @param string $id The row id (never trusted; read under the subject's scope first).
	 * @param string $actionId The endpoint row action.
	 *
	 * @return JSONResponse The relayed answer, or 401 / 403 / 404 / 409 / 502.
	 *
	 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
	 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-must-be-offered-only-on-the-rows-its-rowwhen-names
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function forward(string $register, string $schema, string $id, string $actionId): JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$match = $this->authorisedRowAction(subject: $subject, register: $register, schema: $schema, actionId: $actionId);
		if ($match === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$row = $this->ownedRow(match: $match, subject: $subject, target: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($row === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ((new RowActionResolver())->rowMatches(action: $match['action'], row: $row) === false) {
			return new JSONResponse(['error' => 'not_offered'], Http::STATUS_CONFLICT);
		}

		$rowId = $this->rowId(row: $row, fallback: $id);
		$body = $this->forwardBody(match: $match, subject: $subject, rowId: $rowId);
		if ($body === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		// A declared `scopeClaim` rides, server-resolved, inside the signed
		// assertion (case-actions-sign-a-document D3), the way filinq's `sign`
		// receives its `signerEmail`; unresolved, the forward stops here.
		$scopeValue = $this->declaredScopeValue(match: $match, subject: $subject);
		if ($scopeValue === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		// Recorded once the forward is authorised, whatever the leaf app then
		// answers: the audited fact is that the subject invoked it (as
		// ContributionController::action()).
		$this->auditor->record(
			verb: 'forward',
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			register: $match['app'],
			schema: $actionId,
			id: $rowId,
			jti: (string)($subject['jti'] ?? '')
		);

		$response = $this->forwarder->forward(action: $match['action'], subject: $subject, whitelisted: $body, scopeValue: $scopeValue);
		if ($response === null) {
			return new JSONResponse(['error' => 'forward_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($this->forwarder->decodeBody(response: $response), $response->getStatusCode());
	}//end forward()

	/**
	 * The subject's collection on this register and schema that offers the
	 * action on its rows, with the action and its app, or null (403).
	 *
	 * The action must be an endpoint row action of the SAME contribution, the
	 * subject's trust must meet both the collection's and the action's
	 * minTrust, and the endpoint must be instance-local.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $register The requested register.
	 * @param string $schema The requested schema.
	 * @param string $actionId The requested action.
	 *
	 * @return array{collection: array<string, mixed>, action: array<string, mixed>, app: string}|null
	 */
	private function authorisedRowAction(array $subject, string $register, string $schema, string $actionId): ?array {
		$collectionId = (string)$this->request->getParam('collection', '');
		foreach (($this->registry->aggregateFor($subject)['contributions'] ?? []) as $contribution) {
			foreach (($contribution['collections'] ?? []) as $collection) {
				if (($collection['register'] ?? '') !== $register
					|| ($collection['schema'] ?? '') !== $schema
					|| ($collectionId !== '' && ($collection['id'] ?? '') !== $collectionId)
				) {
					continue;
				}

				// The first collection on this register and schema decides, as
				// on the object read; `?collection=` picks another.
				$action = $this->offeredAction(contribution: $contribution, collection: $collection, actionId: $actionId);
				if ($action === null || $this->trustAndEndpointHold(subject: $subject, collection: $collection, action: $action) === false) {
					return null;
				}

				return ['collection' => $collection, 'action' => $action, 'app' => (string)($contribution['app'] ?? '')];
			}//end foreach
		}//end foreach

		return null;
	}//end authorisedRowAction()

	/**
	 * The server-resolved value of the row action's declared `scopeClaim`:
	 * '' when it declares none, null when it declares one that does not
	 * resolve for this subject.
	 *
	 * @param array{collection: array<string, mixed>, action: array<string, mixed>, app: string} $match The authorised row action.
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/case-actions-sign-a-document/specs/portal-contribution-contract/spec.md#requirement-frozen-assertion-wire-format
	 */
	private function declaredScopeValue(array $match, array $subject): ?string {
		$scopeClaim = ($match['action']['scopeClaim'] ?? '');
		if (is_string($scopeClaim) === false || $scopeClaim === '') {
			return '';
		}

		$value = $this->reader->resolveScopeValue(scopeClaim: $scopeClaim, contributingApp: $match['app'], subject: $subject);
		if ($value === null || $value === '') {
			return null;
		}

		return $value;
	}//end declaredScopeValue()

	/**
	 * The endpoint row action a collection offers under this id, or null.
	 *
	 * @param array<string, mixed> $contribution The contribution holding the collection.
	 * @param array<string, mixed> $collection The normalised collection.
	 * @param string $actionId The requested action.
	 *
	 * @return array<string, mixed>|null
	 */
	private function offeredAction(array $contribution, array $collection, string $actionId): ?array {
		if (in_array($actionId, (array)($collection['rowActions'] ?? []), true) === false) {
			return null;
		}

		$resolver = new RowActionResolver();
		foreach (($contribution['actions'] ?? []) as $action) {
			if (is_array($action) === true && ($action['id'] ?? '') === $actionId && $resolver->isEndpointRowAction(action: $action) === true) {
				return $action;
			}
		}

		return null;
	}//end offeredAction()

	/**
	 * Whether the subject's trust meets the collection and the action, and the
	 * endpoint is safe to call.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed> $collection The collection.
	 * @param array<string, mixed> $action The action.
	 *
	 * @return bool
	 */
	private function trustAndEndpointHold(array $subject, array $collection, array $action): bool {
		$trust = ($subject['trust'] ?? '');

		return PortalSessionService::trustSatisfies($trust, ($collection['minTrust'] ?? null)) === true
			&& PortalSessionService::trustSatisfies($trust, ($action['minTrust'] ?? null)) === true
			&& $this->forwarder->isForwardable(action: $action) === true;
	}//end trustAndEndpointHold()

	/**
	 * The row when the subject's collection scope reads it, else null (one
	 * 404 for a foreign and a missing row alike).
	 *
	 * @param array{collection: array<string, mixed>, action: array<string, mixed>, app: string} $match The authorised row action.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array{register: string, schema: string, id: string} $target The route parameters.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ownedRow(array $match, array $subject, array $target): ?array {
		$collection = $match['collection'];

		return $this->reader->readObject(
			register: $target['register'],
			schema: $target['schema'],
			scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			id: $target['id'],
			organisation: (string)($subject['organisation'] ?? ''),
			scopeClaim: (string)($collection['scopeClaim'] ?? ''),
			contributingApp: $match['app'],
			via: ($collection['via'] ?? null),
			audience: (string)($subject['audience'] ?? ''),
			fields: ($collection['fields'] ?? null)
		);
	}//end ownedRow()

	/**
	 * The forwarded body: the action's `fields` whitelist of the request
	 * params, then the proven row id under `rowField`, then the resolved scope
	 * under a declared `subjectField`. Null when that scope does not resolve.
	 *
	 * @param array{collection: array<string, mixed>, action: array<string, mixed>, app: string} $match The authorised row action.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $rowId The proven row id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function forwardBody(array $match, array $subject, string $rowId): ?array {
		$action = $match['action'];
		$body = [];
		foreach ((array)($action['fields'] ?? []) as $field) {
			if (is_string($field) === false) {
				continue;
			}

			$value = $this->request->getParam($field);
			if ($value !== null) {
				$body[$field] = $value;
			}
		}

		$body[(string)$action['rowField']] = $rowId;

		if (is_string($action['subjectField'] ?? null) === true) {
			$scope = $this->reader->resolveScopeValue(
				scopeClaim: (string)($action['scopeClaim'] ?? ''),
				contributingApp: $match['app'],
				subject: $subject
			);
			if ($scope === null || $scope === '') {
				return null;
			}

			$body[$action['subjectField']] = $scope;
		}

		return $body;
	}//end forwardBody()

	/**
	 * The row's own identifier, else the path id it was read by.
	 *
	 * @param array<string, mixed> $row The proven row.
	 * @param string $fallback The path id.
	 *
	 * @return string
	 */
	private function rowId(array $row, string $fallback): string {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($self['uuid'] ?? null), ($self['id'] ?? null)] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return $fallback;
	}//end rowId()
}//end class
