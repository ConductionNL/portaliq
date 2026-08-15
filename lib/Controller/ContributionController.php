<?php

/**
 * Portaliq Contribution Controller
 *
 * Protected portal API: returns the aggregated portal contributions the
 * authenticated subject may see (the declarative manifest — collections +
 * actions each contributing app registered), reads collection objects, creates
 * whitelisted objects, and forwards declared endpoint actions server-to-server
 * (contract v2, A6). Guarded by PortalAuthMiddleware via the PortalProtected
 * marker, so it fails closed (401) without a valid session; the subject is read
 * from the validated bearer, never from a client param. Every handler
 * authorises against the subject's OWN aggregated manifest — already
 * trust-filtered by the registry — and re-checks the matched entry's minTrust
 * as defense in depth (ADR-005).
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
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 * @spec openspec/changes/contract-v2/tasks.md#T3
 * @spec openspec/changes/contract-v2/tasks.md#T5
 * @spec openspec/changes/contract-v2/tasks.md#T8
 * @spec openspec/changes/portal-inbox-v2/tasks.md#T02
 * @spec openspec/changes/portal-inbox-v2/tasks.md#T03
 * @spec openspec/changes/portal-inbox-v2/tasks.md#T04
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T06
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalInboxReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Serves the authenticated subject's aggregated portal contributions.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 *
 * @SuppressWarnings(PHPMD.StaticAccess)             -- PortalSessionService::trustSatisfies
 * is deliberately THE single trust comparator (contract-v2 design decision);
 * every re-check calls it statically so the ordering can never fork.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- the complexity is
 * fail-closed authorisation guards on an auth edge (ADR-005), one per attack
 * surface; collapsing them would trade auditability for a score.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)   -- one dependency per
 * distinct scoped OpenRegister capability (read/write/file/schema/inbox/audit),
 * ADR-022; folding them into a facade would hide which security boundary each
 * handler actually exercises.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     -- one handler per public
 * portal endpoint, each carrying its own IDOR/trust rationale inline (ADR-005);
 * splitting would scatter one security boundary across classes.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     -- one method per routed
 * endpoint (appinfo/routes.php); the count tracks the API surface, not
 * incidental complexity.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   -- see ExcessiveParameterList.
 */
class ContributionController extends Controller implements PortalProtected {
	/**
	 * HTTP methods an endpoint action may declare (contract v2, A6).
	 */
	private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param PortalContributionRegistry $registry The contribution aggregator.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalObjectReader $reader Subject-scoped OR reader.
	 * @param PortalObjectWriter $writer Subject-scoped OR writer.
	 * @param PortalFileWriter $fileWriter Subject-scoped OR file attach.
	 * @param PortalFileReader $fileReader Subject-scoped OR file list/download.
	 * @param PortalSchemaReader $schemaReader Scoped OR schema-definition reader.
	 * @param PortalInboxReader $inboxReader Cross-app inbox aggregation + unread count (portal-inbox-v2).
	 * @param PortalAuditHook $auditHook Fail-safe audit-record call site (download).
	 * @param PortalActionForwarder $forwarder Transport half of the A6 action forward
	 *                                         (signed assertion, instance-local URL,
	 *                                         502-on-transport-failure).
	 * @param AuditTrailService $auditor Records create/update/forward mutations
	 *                                   (portal-session-hardening-v2).
	 * @param SubmissionReceiptService $receiptService WMEBV ontvangstbevestiging + proof-log
	 *                                                 generator, called after every
	 *                                                 successful create (fail-safe; never
	 *                                                 affects the response).
	 * @param NotificationDispatchService $notificationDispatch Fires the `status.changed` trigger
	 *                                                          (portal-notifications-dispatch) on a
	 *                                                          successful transition; fail-safe,
	 *                                                          never affects the response.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalSessionService $session,
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly PortalFileWriter $fileWriter,
		private readonly PortalFileReader $fileReader,
		private readonly PortalSchemaReader $schemaReader,
		private readonly PortalInboxReader $inboxReader,
		private readonly PortalAuditHook $auditHook,
		private readonly PortalActionForwarder $forwarder,
		private readonly AuditTrailService $auditor,
		private readonly SubmissionReceiptService $receiptService,
		private readonly NotificationDispatchService $notificationDispatch,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Resolve the subject from the bearer (fail-closed). PortalAuthMiddleware
	 * has already gated protected access; this re-derives the subject reliably
	 * for the handler without depending on request-scoped DI sharing.
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()

	/**
	 * List the contributions the authenticated subject may see — or, when no
	 * bearer resolves, the anonymous-reachable surface (portal-page-provisioning).
	 *
	 * The route is marked #[PublicPage] because portal subjects are not
	 * Nextcloud users; PortalAuthMiddleware enforces the bearer session and
	 * fails closed before this method runs UNLESS at least one anonymous
	 * entry exists anywhere in the fleet, in which case a no-bearer request
	 * reaches here with `subject() === null` and gets the anonymous
	 * aggregate instead of a page-shaped 401 — the anonymous visitor's SPA
	 * needs the page layout (labels, richText, field configs) before it can
	 * submit anything.
	 *
	 * @return JSONResponse The aggregated contribution manifest (subject or
	 *                      anonymous), carrying the subject's own unread
	 *                      inbox count when authenticated (portal-inbox-v2 T04;
	 *                      always `0` on the anonymous path — there is no
	 *                      inbox without a subject).
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T04
	 * @spec openspec/changes/portal-inbox-v2/tasks.md#T04
	 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			// Reached only when PortalAuthMiddleware already confirmed at
			// least one anonymous entry exists (fail-closed gate); a direct
			// re-check would just repeat the same aggregation, so the
			// anonymous aggregate is served straight away.
			$aggregate = $this->registry->aggregateAnonymous();
			$aggregate['unreadCount'] = 0;
			return new JSONResponse($aggregate);
		}

		$aggregate = $this->registry->aggregateFor($subject);
		$aggregate['unreadCount'] = $this->inboxReader->unreadCount(subject: $subject, aggregate: $aggregate);

		return new JSONResponse($aggregate);
	}//end index()

	/**
	 * The subject's unified inbox: every `kind: inbox` collection across ALL
	 * their contributions, merged, sorted by `receivedAt` descending, and
	 * tagged with its source app/label (portal-inbox-v2 T02). Each row passes
	 * through the IDENTICAL per-row subject + tenant + trust boundary as a
	 * normal collection read — PortalInboxReader adds no authorisation of its
	 * own, it only fans the subject's own (already trust-filtered) aggregate
	 * out across every inbox collection. Fails closed to an empty inbox on
	 * any per-collection OR error.
	 *
	 * @return JSONResponse The merged inbox rows, or 401.
	 *
	 * @spec openspec/changes/portal-inbox-v2/tasks.md#T02
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function inbox(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$aggregate = $this->registry->aggregateFor($subject);
		$messages = $this->inboxReader->aggregateInbox(subject: $subject, aggregate: $aggregate);

		return new JSONResponse(['messages' => $messages]);
	}//end inbox()

	/**
	 * Mark ONE inbox message read (portal-inbox-v2 T03) — tamper-proof: the
	 * (register, schema) must resolve to a `kind: inbox` collection in the
	 * subject's own contributions (the same IDOR guard as collection()/
	 * object()), the matched collection's minTrust is re-checked (403 before
	 * any write), and the write goes through PortalObjectWriter::updateObject()
	 * with a LITERAL `['read' => true]` payload — never the request body — so
	 * no other field can ever be written through this endpoint regardless of
	 * what a client sends. updateObject() re-verifies ownership (scopeField +
	 * tenant) against OpenRegister BEFORE writing, so a foreign-owned or
	 * non-existent id yields the SAME 404 as every other scoped write — no
	 * existence oracle.
	 *
	 * @param string $register The register of the inbox collection.
	 * @param string $schema The schema of the inbox collection.
	 * @param string $id The message id (never trusted; ownership re-checked server-side).
	 *
	 * @return JSONResponse The updated message, or 401 / 403 / 404.
	 *
	 * @spec openspec/changes/portal-inbox-v2/tasks.md#T03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function markRead(string $register, string $schema, string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$collectionId = (string)$this->request->getParam('collection', '');
		$match = $this->authorisedInboxCollection(subject: $subject, register: $register, schema: $schema, collectionId: $collectionId);
		if ($match === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$collection = $match['collection'];

		// Defense in depth (contract v2, A3): re-check the matched collection's
		// minTrust — 403 before any OpenRegister write.
		if (PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($collection['minTrust'] ?? null)) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		// Resolve the OWNERSHIP scope value the SAME way every scoped write does:
		// a declared scopeClaim resolves server-side; an absent/malformed claim
		// fails closed to 404 with no write.
		$scopeValue = $this->reader->resolveScopeValue(
			scopeClaim: (string)($collection['scopeClaim'] ?? ''),
			contributingApp: $match['app'],
			subject: $subject
		);
		if ($scopeValue === null || $scopeValue === '') {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		// The LITERAL payload — never the request body. Whatever extra fields a
		// client sends are simply never read, so `read` is the only field this
		// endpoint can ever change.
		$updated = $this->writer->updateObject(
			register: $register,
			schema: $schema,
			scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
			subjectRef: $scopeValue,
			organisation: (string)($subject['organisation'] ?? ''),
			id: $id,
			data: ['read' => true]
		);

		// Null = ownership re-verification failed OR the row does not exist —
		// a single 404, indistinguishable, and nothing was written.
		if ($updated === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['object' => $updated]);
	}//end markRead()

	/**
	 * Find a `kind: inbox` collection matching (register, schema) in the
	 * subject's aggregated contributions — the SAME IDOR guard as
	 * authorisedCollection(), narrowed to inbox collections only, so mark-read
	 * can never be pointed at an arbitrary non-inbox collection/schema.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $register The requested register.
	 * @param string $schema The requested schema.
	 * @param string $collectionId Optional collection id disambiguating a shared register+schema.
	 *
	 * @return array{collection: array<string, mixed>, app: string}|null
	 *
	 * @spec openspec/changes/portal-inbox-v2/tasks.md#T03
	 */
	private function authorisedInboxCollection(array $subject, string $register, string $schema, string $collectionId = ''): ?array {
		$match = $this->authorisedCollection(subject: $subject, register: $register, schema: $schema, collectionId: $collectionId);
		if ($match === null || ($match['collection']['kind'] ?? '') !== 'inbox') {
			return null;
		}

		return $match;
	}//end authorisedInboxCollection()

	/**
	 * Read the objects in one contribution collection, scoped to the subject.
	 *
	 * Authorises first: the (register, schema) must appear in one of the
	 * subject's own contributions, so a subject can only read collections its
	 * apps granted it. The subject reference is derived from the bearer,
	 * never the client. A declared `fields` whitelist (any collection kind,
	 * including inbox) is forwarded to the reader untouched — `null` means
	 * "no projection, full rows" (field-projection change).
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 *
	 * @return JSONResponse The subject's rows, or 401 / 403.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T05
	 * @spec openspec/changes/contract-v2/tasks.md#T3
	 * @spec openspec/changes/contract-v2/tasks.md#T5
	 * @spec openspec/changes/field-projection/tasks.md#T2
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function collection(string $register, string $schema): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		// The optional `collection` query param disambiguates multiple
		// collections that share a register+schema (contract v2 — scopeClaim /
		// via views over the same schema). Absent → first-by-(register,schema),
		// preserving the pre-existing behaviour.
		$collectionId = (string)$this->request->getParam('collection', '');

		$match = $this->authorisedCollection(subject: $subject, register: $register, schema: $schema, collectionId: $collectionId);
		if ($match === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$collection = $match['collection'];

		// Defense in depth (contract v2, A3): the aggregate is already
		// trust-filtered, but the matched entry's minTrust is re-checked here
		// so a direct request can never slip below the threshold — 403 before
		// any OpenRegister call.
		if (PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($collection['minTrust'] ?? null)) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$objects = $this->reader->readCollection(
			register: $register,
			schema: $schema,
			scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			limit: 200,
			scopeClaim: (string)($collection['scopeClaim'] ?? ''),
			contributingApp: $match['app'],
			via: ($collection['via'] ?? null),
			audience: (string)($subject['audience'] ?? ''),
			fields: ($collection['fields'] ?? null),
			// Declared narrowing filter (e.g. a supertype discriminator such as
			// pipelinq's `ticketType`). The reader applies it BEFORE the scope
			// filter, so it can only ever subset the subject's own rows.
			filter: (array)($collection['filter'] ?? [])
		);

		return new JSONResponse(['register' => $register, 'schema' => $schema, 'objects' => $objects]);
	}//end collection()

	/**
	 * Find the collection matching (register, schema) in the subject's
	 * aggregated contributions, or null when the subject is not entitled to it.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $register The requested register.
	 * @param string $schema The requested schema.
	 * @param string $collectionId Optional collection id disambiguating a shared register+schema.
	 *
	 * @return array{collection: array<string, mixed>, app: string}|null
	 */
	private function authorisedCollection(array $subject, string $register, string $schema, string $collectionId = ''): ?array {
		$aggregate = $this->registry->aggregateFor($subject);
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			foreach (($contribution['collections'] ?? []) as $collection) {
				if (($collection['register'] ?? '') !== $register || ($collection['schema'] ?? '') !== $schema) {
					continue;
				}

				// When a collection id is supplied it MUST match — this is what
				// distinguishes two collections that share a register+schema
				// (e.g. a direct view and a scopeClaim/via view of the same
				// schema). An empty id keeps the first-match fallback.
				if ($collectionId !== '' && ($collection['id'] ?? '') !== $collectionId) {
					continue;
				}

				return [
					'collection' => $collection,
					'app' => (string)($contribution['app'] ?? ''),
				];
			}
		}

		return null;
	}//end authorisedCollection()

	/**
	 * Read a SINGLE object in a contribution collection, scoped to the subject
	 * (portal-scoped-crud, ADR-062 Phase 1).
	 *
	 * Authorises identically to collection(): the (register, schema) must
	 * appear in one of the subject's own contributions (honouring the
	 * `?collection=` disambiguation), and the matched collection's minTrust is
	 * re-checked as defense in depth — 403 before any OpenRegister call. The
	 * reader re-verifies per-subject ownership of the fetched object, so a
	 * null result (foreign owner OR non-existent id, indistinguishable) yields
	 * 404 — NO existence oracle.
	 *
	 * When the collection declares `filesDownload: true` (portal-document-download),
	 * the ALREADY-owned object is enriched with a safe `_files` listing (id/name/size
	 * only — no stored path) so the SPA detail view can render download links for a
	 * row it just proved the subject owns; a collection that has not opted in never
	 * gets a `_files` key, and the listing itself degrades to `[]` on any OR error.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 * @param string $id The object id (never trusted; ownership re-checked server-side).
	 *
	 * @return JSONResponse The subject's object, or 401 / 403 / 404.
	 *
	 * @spec openspec/changes/portal-scoped-crud/tasks.md#T3
	 * @spec openspec/specs/supplier-portal/spec.md#scoped-file-download-re-verifies-ownership-before-serving-a-byte
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function object(string $register, string $schema, string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$collectionId = (string)$this->request->getParam('collection', '');

		$match = $this->authorisedCollection(subject: $subject, register: $register, schema: $schema, collectionId: $collectionId);
		if ($match === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$collection = $match['collection'];

		// Defense in depth (contract v2, A3): re-check the matched collection's
		// minTrust — 403 before any OpenRegister call.
		if (PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($collection['minTrust'] ?? null)) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$object = $this->reader->readObject(
			register: $register,
			schema: $schema,
			scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			id: $id,
			organisation: (string)($subject['organisation'] ?? ''),
			scopeClaim: (string)($collection['scopeClaim'] ?? ''),
			contributingApp: $match['app'],
			via: ($collection['via'] ?? null),
			audience: (string)($subject['audience'] ?? ''),
			fields: ($collection['fields'] ?? null)
		);

		// Null = not the subject's OR does not exist — a single 404, no oracle.
		if ($object === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		// Opt-in only, and only after ownership is already proven above — the
		// listing itself never widens which ROWS are visible, only what a row
		// the subject already owns additionally shows.
		if (($collection['filesDownload'] ?? false) === true) {
			$object['_files'] = $this->fileReader->listFiles(register: $register, schema: $schema, id: $id);
		}

		return new JSONResponse(['object' => $object]);
	}//end object()

	/**
	 * Attach an uploaded file to an object the subject owns (the file-upload
	 * block, ADR-063). The collection MUST declare `filesUpload: true`, and
	 * ownership is re-verified through the SAME scoped reader as the single-object
	 * read (scopeField / scopeClaim / via) BEFORE the file is accepted — a foreign
	 * or absent id is a single 404 with nothing written. The file lands in the
	 * object's OpenRegister folder via the shared FileService (RBAC bypassed;
	 * Portaliq is the trusted scoper). Multipart field name: `file`.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 * @param string $id The object to attach to.
	 *
	 * @return JSONResponse The attached file metadata, or 401 / 403 / 404 / 400 / 502.
	 *
	 * The rate limit below is tighter than the read paths: an upload costs
	 * storage, not just a query.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-file-attachment-on-a-subject-owned-object
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function uploadFile(string $register, string $schema, string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$collectionId = (string)$this->request->getParam('collection', '');
		$match = $this->authorisedCollection(subject: $subject, register: $register, schema: $schema, collectionId: $collectionId);
		if ($match === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$collection = $match['collection'];

		// The collection must explicitly opt in to file uploads.
		if (($collection['filesUpload'] ?? false) !== true) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		if (PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($collection['minTrust'] ?? null)) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		// Re-verify ownership with the full scope resolution — 404 (no oracle)
		// when the object is not the subject's or does not exist.
		$owned = $this->reader->readObject(
			register: $register,
			schema: $schema,
			scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			id: $id,
			organisation: (string)($subject['organisation'] ?? ''),
			scopeClaim: (string)($collection['scopeClaim'] ?? ''),
			contributingApp: $match['app'],
			via: ($collection['via'] ?? null),
			audience: (string)($subject['audience'] ?? ''),
			fields: ($collection['fields'] ?? null)
		);
		if ($owned === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$upload = $this->readUploadedFile();
		if ($upload === null) {
			return new JSONResponse(['error' => 'no_file'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->fileWriter->attachFile(
			register: $register,
			schema: $schema,
			id: $id,
			fileName: $upload['name'],
			content: $upload['content']
		);
		if ($result === null) {
			return new JSONResponse(['error' => 'upload_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['file' => $result]);
	}//end uploadFile()

	/**
	 * Read the multipart upload (`file`) into a sanitised {name, content} pair,
	 * or null when there is nothing usable to attach (the caller answers 400).
	 *
	 * `IRequest::getUploadedFile()` returns `[]` when the field is absent, so
	 * the error/tmp_name checks also cover the missing case. Readability is
	 * probed BEFORE the read so the read itself needs no error-control
	 * operator; the `false` check remains as the residual guard.
	 *
	 * The filename is reduced to a basename — a client path is never trusted —
	 * and degrades to `upload` when that leaves nothing usable.
	 *
	 * @return array{name: string, content: string}|null
	 */
	private function readUploadedFile(): ?array {
		$uploaded = $this->request->getUploadedFile('file');
		$tmpName = (string)($uploaded['tmp_name'] ?? '');
		if ((int)($uploaded['error'] ?? 1) !== 0 || $tmpName === '' || is_readable($tmpName) === false) {
			return null;
		}

		$content = file_get_contents($tmpName);
		if ($content === false) {
			return null;
		}

		$fileName = basename((string)($uploaded['name'] ?? 'upload'));
		if ($fileName === '' || $fileName === '.' || $fileName === '..') {
			$fileName = 'upload';
		}

		return ['name' => $fileName, 'content' => $content];
	}//end readUploadedFile()

	/**
	 * Stream a file attached to an object the subject owns
	 * (portal-document-download — the read-side counterpart of uploadFile()).
	 *
	 * The collection MUST declare `filesDownload: true`, and ownership is
	 * re-verified through the SAME scoped reader as the single-object read
	 * (scopeField / scopeClaim / via) BEFORE the file is resolved. Per the
	 * identical-404 discipline, a non-opted-in collection, a foreign/absent
	 * object, and a `fileId` that does not resolve inside the owned object's
	 * folder ALL return the exact same 404 body — no existence oracle can
	 * distinguish "not opted in" from "not yours" from "does not exist", and
	 * the raw stored path is never exposed. A successful download invokes the
	 * audit hook (verb `download`) — a hook failure never affects the
	 * response already being streamed.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 * @param string $id The object the file hangs off (never trusted; ownership re-checked server-side).
	 * @param string $fileId The requested file's id (never trusted as a capability).
	 *
	 * @return Response The streamed file, or a 401 / 403 / 404 JSON error.
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#scoped-file-download-re-verifies-ownership-before-serving-a-byte
	 * @spec openspec/specs/supplier-portal/spec.md#download-is-opt-in-per-collection-fail-closed
	 * @spec openspec/specs/supplier-portal/spec.md#identical-404-discipline-no-existence-oracle
	 * @spec openspec/specs/supplier-portal/spec.md#download-emits-an-audit-hook
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function downloadFile(string $register, string $schema, string $id, string $fileId): Response {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$collectionId = (string)$this->request->getParam('collection', '');
		$match = $this->authorisedCollection(subject: $subject, register: $register, schema: $schema, collectionId: $collectionId);
		if ($match === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$collection = $match['collection'];

		if (PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($collection['minTrust'] ?? null)) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		// From here on every refusal is the IDENTICAL 404 — opt-in, ownership,
		// and file resolution are indistinguishable to the client (no oracle).
		if (($collection['filesDownload'] ?? false) !== true) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		// Re-verify ownership with the full scope resolution BEFORE the file is
		// ever resolved — a foreign or non-existent object never reaches the
		// file layer.
		$owned = $this->reader->readObject(
			register: $register,
			schema: $schema,
			scopeField: (string)($collection['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			id: $id,
			organisation: (string)($subject['organisation'] ?? ''),
			scopeClaim: (string)($collection['scopeClaim'] ?? ''),
			contributingApp: $match['app'],
			via: ($collection['via'] ?? null),
			audience: (string)($subject['audience'] ?? ''),
			fields: ($collection['fields'] ?? null)
		);
		if ($owned === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$stream = $this->fileReader->streamFile(register: $register, schema: $schema, id: $id, fileId: $fileId);
		if ($stream === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$this->auditHook->download(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			register: $register,
			schema: $schema,
			id: $id
		);

		return $stream;
	}//end downloadFile()

	/**
	 * Return an OpenRegister schema DEFINITION by slug, for the schema-driven
	 * portal frontend (the tilburg-woo engine repointed at /portal/api). The
	 * store fetches a schema by slug to build table headers + forms; the scoped
	 * API answers it, gated so a subject may only introspect a schema their
	 * manifest references (via a collection or an action, any register). RBAC is
	 * bypassed in the reader (schemas are metadata, not subject data), and the
	 * shape is OpenRegister's own (`properties` etc.) so the store consumes it
	 * unchanged.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return JSONResponse The schema definition, or 401 / 403 / 404.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-schema-introspection
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	public function schema(string $schema): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		// The subject may only introspect a schema their manifest references.
		$referenced = false;
		$aggregate = $this->registry->aggregateFor(subject: $subject);
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			foreach (['collections', 'actions'] as $section) {
				foreach (($contribution[$section] ?? []) as $entry) {
					if ((string)($entry['schema'] ?? '') === $schema) {
						$referenced = true;
						break 3;
					}
				}
			}
		}

		if ($referenced === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$definition = $this->schemaReader->readSchema(slug: $schema);
		if ($definition === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($definition);
	}//end schema()

	/**
	 * Create an object in a collection, owned by the subject.
	 *
	 * Authorises against the subject's own contributions: there must be a
	 * declared `type: create` action for this (register, schema). Only the
	 * fields that action whitelists are accepted; the subject ref and tenant are
	 * stamped server-side. A subject can therefore only create records it is
	 * entitled to, owned by itself.
	 *
	 * On a SUCCESSFUL create, `SubmissionReceiptService::record()` fires the
	 * WMEBV ontvangstbevestiging + burden-of-proof log (wmebv-submission-
	 * receipts) with the SAME whitelisted field map just persisted (never the
	 * raw request body). The call is fail-safe by construction — it never
	 * throws and never affects this response — so a receipt/log side-effect
	 * can never turn a successful submission into a failed one. The receipt
	 * write itself, when successful, fires the `message.created` notification
	 * trigger (portal-notifications-dispatch, inside SubmissionReceiptService).
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 *
	 * @return JSONResponse The created object, or 401 / 403 / 502.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T06
	 * @spec openspec/changes/contract-v2/tasks.md#T3
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
	 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
	 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function create(string $register, string $schema): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->createAnonymous(register: $register, schema: $schema);
		}

		$match = $this->authorisedCreateAction(subject: $subject, register: $register, schema: $schema);
		if ($match === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$action = $match['action'];

		// Defense in depth (contract v2, A3): re-check the matched action's
		// minTrust — 403 before any OpenRegister write.
		if (PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($action['minTrust'] ?? null)) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$data = $this->whitelist(fields: (array)($action['fields'] ?? []));

		// A declared action `defaults` map is stamped server-side over the
		// whitelisted client payload. It carries values the client must not choose
		// — notably a supertype discriminator (pipelinq's `ticketType`), which is
		// required by the schema but is never a client-editable field. Applied
		// AFTER the whitelist so a client can never override it.
		foreach ((array)($action['defaults'] ?? []) as $key => $value) {
			if (is_string($key) === true && $key !== '') {
				$data[$key] = $value;
			}
		}

		$created = $this->writer->createObject(
			register: $register,
			schema: $schema,
			scopeField: (string)($action['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			data: $data
		);

		if ($created === null) {
			return new JSONResponse(['error' => 'write_failed'], Http::STATUS_BAD_GATEWAY);
		}

		$this->auditor->record(
			verb: 'create',
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			register: $register,
			schema: $schema,
			id: $this->extractId(row: $created),
			jti: (string)($subject['jti'] ?? '')
		);

		// WMEBV (wmebv-submission-receipts): the domain create is already
		// authoritative and has already succeeded above — this is a fail-safe
		// follow-on, never a gate. $data is the exact whitelisted+defaults map
		// just persisted (never the raw request body).
		$this->receiptService->record(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			appId: $match['app'],
			actionId: (string)($action['id'] ?? ''),
			whitelistedData: $data,
			audience: (string)($subject['audience'] ?? '')
		);

		return new JSONResponse(['object' => $created]);
	}//end create()

	/**
	 * Find a `type: create` action for (register, schema) in the subject's
	 * contributions, or null when the subject is not entitled to create there.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $register The requested register.
	 * @param string $schema The requested schema.
	 *
	 * @return array{action: array<string, mixed>, app: string}|null The matched
	 *                                                               action and its contributing app (the
	 *                                                               WMEBV receipt's `appId`), or null.
	 */
	private function authorisedCreateAction(array $subject, string $register, string $schema): ?array {
		$aggregate = $this->registry->aggregateFor($subject);
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			foreach (($contribution['actions'] ?? []) as $action) {
				if (($action['type'] ?? '') === 'create'
					&& ($action['register'] ?? '') === $register
					&& ($action['schema'] ?? '') === $schema
				) {
					return ['action' => $action, 'app' => (string)($contribution['app'] ?? '')];
				}
			}
		}

		return null;
	}//end authorisedCreateAction()

	/**
	 * Create an object with NO subject at all (portal-page-provisioning) —
	 * the anonymous sibling of the bearer-authenticated create() path.
	 * Reached only when `subject()` is null; PortalAuthMiddleware has
	 * already confirmed an anonymous `type: create` action exists for this
	 * exact `(register, schema)` before letting the request through, but
	 * this re-derives the match independently (defense in depth, the SAME
	 * discipline `authorisedCreateAction()` applies for a bearer subject) —
	 * a mismatch here means 403, not a write. Whitelisting and `defaults`
	 * are applied identically to the authenticated path; the write goes
	 * through `PortalObjectWriter::createAnonymousObject()`, which stamps NO
	 * subject/organisation ownership. There is no subject to audit or issue
	 * a WMEBV receipt against — an anonymous submission is, by definition,
	 * unowned.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 *
	 * @return JSONResponse The created object, or 403 / 502.
	 *
	 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
	 */
	private function createAnonymous(string $register, string $schema): JSONResponse {
		$action = $this->authorisedAnonymousCreateAction(register: $register, schema: $schema);
		if ($action === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$data = $this->whitelist(fields: (array)($action['fields'] ?? []));

		// Server-forced defaults, applied AFTER the whitelist so a client can
		// never override them — identical discipline to the authenticated
		// path (e.g. stamping a placeholder ownership marker a schema's
		// `required` set may mandate, since an anonymous write carries no
		// real subjectRef).
		foreach ((array)($action['defaults'] ?? []) as $key => $value) {
			if (is_string($key) === true && $key !== '') {
				$data[$key] = $value;
			}
		}

		$created = $this->writer->createAnonymousObject(register: $register, schema: $schema, data: $data);
		if ($created === null) {
			return new JSONResponse(['error' => 'write_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['object' => $created]);
	}//end createAnonymous()

	/**
	 * Find an `anonymous: true`, `type: create` action for (register, schema)
	 * in the fleet-wide anonymous aggregate, or null when nothing matches
	 * (portal-page-provisioning).
	 *
	 * @param string $register The requested register.
	 * @param string $schema The requested schema.
	 *
	 * @return array<string, mixed>|null The matched action, or null.
	 *
	 * @spec openspec/specs/portal-page-provisioning/spec.md#requirement-anonymous-submission-must-be-available-without-an-identity-provider
	 */
	private function authorisedAnonymousCreateAction(string $register, string $schema): ?array {
		$aggregate = $this->registry->aggregateAnonymous();
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			foreach (($contribution['actions'] ?? []) as $action) {
				if (($action['type'] ?? '') === 'create'
					&& ($action['anonymous'] ?? false) === true
					&& ($action['register'] ?? '') === $register
					&& ($action['schema'] ?? '') === $schema
				) {
					return $action;
				}
			}
		}

		return null;
	}//end authorisedAnonymousCreateAction()

	/**
	 * Update an object in a collection, owned by the subject (portal-scoped-crud,
	 * ADR-062 Phase 1 — closes the write-IDOR concern, Conduction/portaliq#16).
	 *
	 * Mirrors create()'s authorisation discipline: there must be a declared
	 * `type: update` action for this (register, schema); only the fields that
	 * action whitelists are accepted (the scope field is never whitelisted);
	 * the matched action's minTrust is re-checked (403 before any write). The
	 * writer re-verifies ownership of the client-supplied id against
	 * OpenRegister BEFORE writing, so a null result (not the subject's OR
	 * non-existent, indistinguishable) yields 404 — no existence oracle and no
	 * write.
	 *
	 * On a SUCCESSFUL update, `NotificationDispatchService::dispatch()` fires
	 * the `status.changed` trigger (portal-notifications-dispatch) — a
	 * fail-safe follow-on, never a gate; the matched app's manifest opts in
	 * per rule key.
	 *
	 * @param string $register The register of the collection.
	 * @param string $schema The schema of the collection.
	 * @param string $id The object id (never trusted; ownership re-checked server-side).
	 *
	 * @return JSONResponse The updated object, or 401 / 403 / 404.
	 *
	 * @spec openspec/changes/portal-scoped-crud/tasks.md#T3
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
	 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function update(string $register, string $schema, string $id): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$actionId = (string)$this->request->getParam('action', '');
		$match = $this->authorisedUpdateAction(subject: $subject, register: $register, schema: $schema, actionId: $actionId);
		if ($match === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$action = $match['action'];

		// Defense in depth (contract v2, A3): re-check the matched action's
		// minTrust — 403 before any OpenRegister write.
		if (PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($action['minTrust'] ?? null)) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		// Resolve the OWNERSHIP scope value the SAME way the read path does: a
		// declared `scopeClaim` resolves server-side from the subject's
		// portalAccount, else it is the subjectRef. This lets a transition run on
		// a claim-scoped collection (e.g. a manager approving timesheets scoped
		// by their costCenter claim) while still re-verifying ownership by the
		// resolved value. A declared claim that cannot resolve → 404, no write.
		$scopeValue = $this->reader->resolveScopeValue(
			scopeClaim: (string)($action['scopeClaim'] ?? ''),
			contributingApp: $match['app'],
			subject: $subject
		);
		if ($scopeValue === null || $scopeValue === '') {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$data = $this->whitelist(fields: (array)($action['fields'] ?? []));

		// Server-enforced transition target (contribution-manifest-v3): an update
		// action MAY declare `set` — fixed field values the SERVER applies OVER
		// the client input, so an approve/reject/close transition can never be
		// tampered with by the client. Only whitelisted fields are honoured
		// (defence in depth; the normaliser already dropped non-whitelisted keys).
		$whitelist = (array)($action['fields'] ?? []);
		foreach ((array)($action['set'] ?? []) as $field => $value) {
			if (in_array($field, $whitelist, true) === true) {
				$data[$field] = $value;
			}
		}

		$updated = $this->writer->updateObject(
			register: $register,
			schema: $schema,
			scopeField: (string)($action['scopeField'] ?? 'subjectRef'),
			subjectRef: $scopeValue,
			organisation: (string)($subject['organisation'] ?? ''),
			id: $id,
			data: $data
		);

		// Null = ownership re-verification failed OR the row does not exist —
		// a single 404, indistinguishable, and nothing was written.
		if ($updated === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$this->auditor->record(
			verb: 'update',
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			register: $register,
			schema: $schema,
			id: $id,
			jti: (string)($subject['jti'] ?? '')
		);

		// Portal-notifications-dispatch: the update ALREADY succeeded above —
		// this is a fail-safe follow-on (dispatch() never throws), never a
		// gate. The matched app's manifest opts in per rule key, so a plain
		// (non-status) update action that declares no `status.changed` key
		// simply enqueues nothing.
		$this->notificationDispatch->dispatch(
			ruleKey: NotificationDispatchService::RULE_STATUS_CHANGED,
			appId: $match['app'],
			subject: $subject
		);

		return new JSONResponse(['object' => $updated]);
	}//end update()

	/**
	 * Find a `type: update` action for (register, schema) in the subject's
	 * contributions, or null when the subject is not entitled to update there.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $register The requested register.
	 * @param string $schema The requested schema.
	 * @param string $actionId Optional action id to match exactly
	 *                         (`?action=`); empty = first update action.
	 *
	 * @return array{action: array<string, mixed>, app: string}|null The matched
	 *                                                               action and its contributing app (the
	 *                                                               scopeClaim namespace), or null.
	 *
	 * @spec openspec/changes/portal-scoped-crud/tasks.md#T3
	 */
	private function authorisedUpdateAction(array $subject, string $register, string $schema, string $actionId = ''): ?array {
		$aggregate = $this->registry->aggregateFor($subject);
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			foreach (($contribution['actions'] ?? []) as $action) {
				if (($action['type'] ?? '') !== 'update'
					|| ($action['register'] ?? '') !== $register
					|| ($action['schema'] ?? '') !== $schema
				) {
					continue;
				}

				// When the caller names an action (`?action=`), match it exactly
				// so a specific status transition (e.g. `closeExample` with its
				// server-enforced `set`) is applied — not just the first update
				// action for the schema. No `actionId` keeps the v1 behaviour.
				if ($actionId !== '' && (string)($action['id'] ?? '') !== $actionId) {
					continue;
				}

				return ['action' => $action, 'app' => (string)($contribution['app'] ?? '')];
			}
		}

		return null;
	}//end authorisedUpdateAction()

	/**
	 * Read the request body and keep only the whitelisted fields.
	 *
	 * `claims` is server-managed (contract v2, A4) and is dropped here even if
	 * a contribution mistakenly whitelists it — a client-supplied claim map
	 * must never reach an OpenRegister write.
	 *
	 * @param array<int, string> $fields The permitted field names.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/contract-v2/tasks.md#T5
	 */
	private function whitelist(array $fields): array {
		$data = [];
		foreach ($fields as $field) {
			if ($field === 'claims') {
				continue;
			}

			$value = $this->request->getParam($field);
			if ($value !== null) {
				$data[$field] = $value;
			}
		}

		return $data;
	}//end whitelist()

	/**
	 * Extract a saved row's identifier (`id`/`uuid`, flat or in `@self`) for
	 * the audit trail's target id — the same identifier candidates every
	 * other reader/writer in this app checks.
	 *
	 * @param array<string, mixed> $row The saved/normalised row.
	 *
	 * @return string The identifier, or '' when the row carries none.
	 *
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
	 */
	private function extractId(array $row): string {
		$self = ($row['@self'] ?? null);
		$selfUuid = null;
		$selfId = null;
		if (is_array($self) === true) {
			$selfUuid = ($self['uuid'] ?? null);
			$selfId = ($self['id'] ?? null);
		}

		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), $selfUuid, $selfId] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end extractId()

	/**
	 * Forward a declared endpoint action server-to-server (contract v2, A6).
	 *
	 * Authorises against the subject's OWN aggregated (already trust-filtered)
	 * manifest: the contribution for {appId} must declare an action with
	 * {actionId}, a non-empty instance-local endpoint path, and an allowed
	 * method, and its minTrust is re-checked — otherwise 403 with NO outbound
	 * request. The forward attaches a short-lived signed `X-Portal-Subject`
	 * assertion; the client's own Authorization header is NEVER forwarded, and
	 * full http(s) URLs are rejected (SSRF guard). The domain app's status and
	 * JSON body are relayed as-is; transport failure yields 502.
	 *
	 * When the matched action declares `type: create` and the domain endpoint
	 * relays a 2xx status, `SubmissionReceiptService::record()` fires the SAME
	 * WMEBV ontvangstbevestiging + proof-log follow-on as the direct create()
	 * path (wmebv-submission-receipts) — rebuilding the whitelisted field map
	 * from `$action['fields']` the SAME way whitelist() does, never the raw
	 * relayed body. Fail-safe; never affects this response.
	 *
	 * @param string $appId The contributing app the action belongs to.
	 * @param string $actionId The declared action id.
	 *
	 * @return JSONResponse The relayed response, or 401 / 403 / 502.
	 *
	 * @spec openspec/changes/contract-v2/tasks.md#T8
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
	 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
	 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) -- the audit fact-record
	 * (portal-session-hardening-v2), the WMEBV receipt follow-on
	 * (wmebv-submission-receipts), and the notification dispatch follow-on
	 * (portal-notifications-dispatch) all hang off this ONE authorised-forward
	 * handler by design — each is a single fail-safe branch guarding a
	 * distinct compliance duty; splitting them would separate three
	 * side-effects of the SAME forward from the single 403 gate above them.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function action(string $appId, string $actionId): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$action = $this->authorisedEndpointAction(subject: $subject, appId: $appId, actionId: $actionId);
		if ($action === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		// Recorded once the forward is AUTHORISED — regardless of the domain
		// app's own response status or a transport failure below — because the
		// fact being audited is "the subject invoked this forward", not
		// whether the downstream call happened to succeed. `register`/`schema`
		// have no natural analogue for a forward, so the appId/actionId ride
		// in their place (design.md).
		$this->auditor->record(
			verb: 'forward',
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			register: $appId,
			schema: $actionId,
			id: '',
			jti: (string)($subject['jti'] ?? '')
		);

		// A declared `fields` whitelist rebuilds the forwarded body server-side
		// from ONLY those request params; an action that declares none relays
		// the raw request body as-is (contract v2, A6).
		$whitelisted = null;
		if (array_key_exists('fields', $action) === true) {
			$whitelisted = $this->whitelist(fields: (array)$action['fields']);
		}

		$response = $this->forwarder->forward(action: $action, subject: $subject, whitelisted: $whitelisted);
		if ($response === null) {
			// Transport failure — mirrors the writer's 502 posture. Never leak
			// transport internals to the portal client.
			return new JSONResponse(['error' => 'forward_failed'], Http::STATUS_BAD_GATEWAY);
		}

		$decoded = $this->forwarder->decodeBody(response: $response);
		$status = $response->getStatusCode();

		// WMEBV (wmebv-submission-receipts) — the create branch of action():
		// the domain endpoint is authoritative and has already succeeded (2xx)
		// by the time this fires; fail-safe follow-on only, never a gate.
		if (($action['type'] ?? '') === 'create' && $status >= 200 && $status < 300) {
			$this->receiptService->record(
				subjectRef: (string)($subject['subjectRef'] ?? ''),
				organisation: (string)($subject['organisation'] ?? ''),
				appId: $appId,
				actionId: $actionId,
				whitelistedData: $this->whitelist(fields: (array)($action['fields'] ?? [])),
				audience: (string)($subject['audience'] ?? '')
			);
		}

		return new JSONResponse($decoded, $status);
	}//end action()

	/**
	 * Find an authorised endpoint action {appId, actionId} in the subject's
	 * OWN aggregated manifest, or null (fail-closed 403, no outbound call).
	 *
	 * Requirements (contract v2, A6): the action id matches, the endpoint is a
	 * non-empty INSTANCE-LOCAL absolute path (starts with `/`, no scheme, no
	 * protocol-relative `//` — SSRF guard), the method is allowed, and the
	 * entry's minTrust is satisfied (defense in depth on top of the
	 * trust-filtered aggregate).
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $appId The requested contributing app.
	 * @param string $actionId The requested action id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function authorisedEndpointAction(array $subject, string $appId, string $actionId): ?array {
		$aggregate = $this->registry->aggregateFor($subject);
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			if (($contribution['app'] ?? '') !== $appId) {
				continue;
			}

			foreach (($contribution['actions'] ?? []) as $action) {
				if (($action['id'] ?? '') !== $actionId) {
					continue;
				}

				if ($this->isForwardableAction(action: $action, subject: $subject) === false) {
					return null;
				}

				return $action;
			}//end foreach

			return null;
		}//end foreach

		return null;
	}//end authorisedEndpointAction()

	/**
	 * Whether a matched action is safe to forward: non-empty INSTANCE-LOCAL
	 * endpoint path (SSRF guard: no scheme, no protocol-relative `//`), an
	 * allowed method, and satisfied minTrust — all fail-closed.
	 *
	 * @param array<string, mixed> $action The matched action declaration.
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return bool
	 */
	private function isForwardableAction(array $action, array $subject): bool {
		$endpoint = ($action['endpoint'] ?? null);
		if (is_string($endpoint) === false || $endpoint === '') {
			return false;
		}

		// SSRF guard: instance-local absolute paths ONLY. Full http(s)://
		// URLs and protocol-relative // paths are rejected.
		if (str_starts_with($endpoint, '/') === false
			|| str_starts_with($endpoint, '//') === true
			|| str_contains($endpoint, '://') === true
		) {
			return false;
		}

		$method = strtoupper((string)($action['method'] ?? 'POST'));
		if (in_array($method, self::ALLOWED_METHODS, true) === false) {
			return false;
		}

		return PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($action['minTrust'] ?? null));
	}//end isForwardableAction()
}//end class
