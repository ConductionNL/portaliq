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
use OCA\Portaliq\Contribution\ActionScopeResolver;
use OCA\Portaliq\Contribution\AttachedActionResolver;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Contribution\RowActionInputs;
use OCA\Portaliq\Contribution\RowActionResolver;
use OCA\Portaliq\Contribution\RowIdentifier;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\RequiredFieldsGuard;
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
	 * @spec openspec/changes/attach-to-own-collection/specs/portal-contribution-contract/spec.md#requirement-an-attached-action-must-be-able-to-name-one-collection-and-its-own-app-req-ato-001
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

		$inputs  = new RowActionInputs();
		$refusal = $this->notOffered(inputs: $inputs, match: $match, row: $row);
		if ($refusal !== null) {
			return $refusal;
		}

		$rowId = (new RowIdentifier())->idFor(row: $row, fallback: $id);
		$body = $this->forwardBody(match: $match, subject: $subject, rowId: $rowId);
		if ($body === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$body = $this->withRowInputs(inputs: $inputs, match: $match, row: $row, body: $body);
		if ($body instanceof JSONResponse) {
			return $body;
		}

		return $this->relay(match: $match, subject: $subject, body: $body, ids: ['row' => $rowId, 'action' => $actionId]);
	}//end forward()

	/**
	 * Refuse a body without the action's required fields, audit the forward and
	 * relay the leaf app's answer.
	 *
	 * @param array{collection: array<string, mixed>, action: array<string, mixed>, app: string, rowApp?: string} $match The authorised row action.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array{body: array<string, mixed>, scopeValue: string} $body The forward body and assertion scope value.
	 * @param array{row: string, action: string} $ids The proven row id and the action id, for the audit.
	 *
	 * @return JSONResponse The relayed answer, or 422 / 502.
	 */
	private function relay(array $match, array $subject, array $body, array $ids): JSONResponse {
		// The action's required fields (REQ-SMF-024): refused before the audit
		// and the forward.
		$missing = (new RequiredFieldsGuard())->refusal(action: $match['action'], body: $body['body']);
		if ($missing !== null) {
			return $missing;
		}

		// Recorded once the forward is authorised, whatever the leaf app then
		// answers: the audited fact is that the subject invoked it (as
		// ContributionController::action()).
		$this->auditor->record(
			verb: 'forward',
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			register: $match['app'],
			schema: $ids['action'],
			id: $ids['row'],
			jti: (string)($subject['jti'] ?? '')
		);

		$response = $this->forwarder->forward(action: $match['action'], subject: $subject, whitelisted: $body['body'], scopeValue: $body['scopeValue']);
		if ($response === null) {
			return new JSONResponse(['error' => 'forward_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($this->forwarder->decodeBody(response: $response), $response->getStatusCode());
	}//end relay()

	/**
	 * The refusal when the row does not offer the action: not among the rows
	 * its `rowWhen` names, or not available by its `availableWhen`.
	 *
	 * Offered only on the rows its `availableWhen` names; the button's
	 * absence is a convenience, this is the rule
	 * (case-actions-row-inputs-and-conditions REQ-RAI-004).
	 *
	 * @param RowActionInputs      $inputs The row-action input rules.
	 * @param array<string, mixed> $match  The authorised collection, action and app.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return JSONResponse|null The 409, or null when the row offers the action.
	 */
	private function notOffered(RowActionInputs $inputs, array $match, array $row): ?JSONResponse {
		if ((new RowActionResolver())->rowMatches(action: $match['action'], row: $row) === false) {
			return new JSONResponse(['error' => 'not_offered'], Http::STATUS_CONFLICT);
		}

		$availability = $inputs->availability(action: $match['action'], row: $row);
		if ($availability['available'] === false) {
			return new JSONResponse(['error' => 'not_available', 'message' => $availability['reason']], Http::STATUS_CONFLICT);
		}

		return null;
	}//end notOffered()

	/**
	 * Add the inputs this row declares to the forward body, for exactly the
	 * names it declares (REQ-RAI-002): a name it does not declare is dropped
	 * here, and a required one left empty is a 422 that forwards nothing.
	 *
	 * @param RowActionInputs      $inputs The row-action input rules.
	 * @param array<string, mixed> $match  The authorised collection, action and app.
	 * @param array<string, mixed> $row    The row.
	 * @param array<string, mixed> $body   The forward body and scope value.
	 *
	 * @return array<string, mixed>|JSONResponse The body, or the 422.
	 */
	private function withRowInputs(RowActionInputs $inputs, array $match, array $row, array $body): array|JSONResponse {
		$into = ($match['action']['rowInputs']['into'] ?? null);
		if (is_string($into) === false) {
			return $body;
		}

		$collected = $inputs->collect(action: $match['action'], row: $row, submitted: $this->request->getParam($into));
		if ($collected['errors'] !== []) {
			return new JSONResponse(['error' => 'invalid', 'errors' => $collected['errors']], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$body['body'][$into] = $collected['values'];

		return $body;
	}//end withRowInputs()

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
	 * @return array{collection: array<string, mixed>, action: array<string, mixed>, app: string, rowApp?: string}|null
	 */
	private function authorisedRowAction(array $subject, string $register, string $schema, string $actionId): ?array {
		$collectionId = (string)$this->request->getParam('collection', '');
		$contributions = ($this->registry->aggregateFor($subject)['contributions'] ?? []);
		foreach ($contributions as $contribution) {
			foreach (($contribution['collections'] ?? []) as $collection) {
				if ($this->isRequested(collection: $collection, requested: [$register, $schema, $collectionId]) === false) {
					continue;
				}

				// An action attached to this collection (woo-journey-entry-points
				// D3): `?actionApp=` names its app. That may be the collection's
				// own app, for an action it attaches to one of its own
				// collections (attach-to-own-collection); the lookup then still
				// requires the attachment, never a row action of the same id.
				$actionApp = (string)$this->request->getParam('actionApp', '');
				if ($actionApp !== '') {
					$target = ['collection' => $collection, 'app' => (string)($contribution['app'] ?? '')];
					return $this->attachedMatch(contributions: $contributions, subject: $subject, target: $target, actionApp: $actionApp, actionId: $actionId);
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
	 * Whether a collection is the one the route names: its register and
	 * schema, and its id when `?collection=` gives one.
	 *
	 * @param array<string, mixed>                $collection The collection.
	 * @param array{0: string, 1: string, 2: string} $requested Register, schema, collection id or ''.
	 *
	 * @return bool
	 */
	private function isRequested(array $collection, array $requested): bool {
		[$register, $schema, $collectionId] = $requested;

		return ($collection['register'] ?? '') === $register
			&& ($collection['schema'] ?? '') === $schema
			&& ($collectionId === '' || ($collection['id'] ?? '') === $collectionId);
	}//end isRequested()

	/**
	 * The match for an action another app attaches to this collection, or null.
	 *
	 * The row is still read through THIS collection's scope (`rowApp`); the
	 * action is forwarded to its own app (`app`).
	 *
	 * @param array<int, array<string, mixed>> $contributions The subject's aggregate.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array{collection: array<string, mixed>, app: string} $target The target collection and its app.
	 * @param string $actionApp The app of the action.
	 * @param string $actionId The action id.
	 *
	 * @return array{collection: array<string, mixed>, action: array<string, mixed>, app: string, rowApp: string}|null
	 *
	 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
	 */
	private function attachedMatch(array $contributions, array $subject, array $target, string $actionApp, string $actionId): ?array {
		$collection = $target['collection'];
		$collectionApp = $target['app'];
		$action = (new AttachedActionResolver())->attachedAction(
			contributions: $contributions,
			collectionApp: $collectionApp,
			collection: $collection,
			actionApp: $actionApp,
			actionId: $actionId
		);
		if ($action === null || $this->trustAndEndpointHold(subject: $subject, collection: $collection, action: $action) === false) {
			return null;
		}

		return ['collection' => $collection, 'action' => $action, 'app' => $actionApp, 'rowApp' => $collectionApp];
	}//end attachedMatch()

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
	 * @param array{collection: array<string, mixed>, action: array<string, mixed>, app: string, rowApp?: string} $match The authorised row action.
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
			contributingApp: ($match['rowApp'] ?? $match['app']),
			via: ($collection['via'] ?? null),
			audience: (string)($subject['audience'] ?? ''),
			fields: ($collection['fields'] ?? null)
		);
	}//end ownedRow()

	/**
	 * The forwarded body: the action's `fields` whitelist of the request
	 * params, then the proven row id under `rowField`, then the resolved scope
	 * under a declared `subjectField`, with the declared `scopeClaim`'s value
	 * for the assertion. Null when either does not resolve.
	 *
	 * @param array{collection: array<string, mixed>, action: array<string, mixed>, app: string, rowApp?: string} $match The authorised row action.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $rowId The proven row id.
	 *
	 * @return array{body: array<string, mixed>|null, scopeValue: string}|null The body and assertion scope value, or null (403).
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

		// A declared `subjectField` is stamped from the subject's scope and a
		// declared `scopeClaim` rides in the signed assertion (the way
		// filinq's `sign` receives its `signerEmail`); either one that does
		// not resolve stops the forward.
		return (new ActionScopeResolver(reader: $this->reader))
			->prepare(action: $action, subject: $subject, appId: $match['app'], body: $body);
	}//end forwardBody()
}//end class
