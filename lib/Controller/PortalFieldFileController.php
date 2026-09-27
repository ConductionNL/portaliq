<?php

/**
 * Portaliq Portal Field File Controller (assignment-portal-file-upload)
 *
 * Uploads one file into a declared file field of an object the subject owns:
 * a pupil's work into learniq's `Submission.attachmentRefs`, straight from the
 * portal form that created the submission.
 *
 * The order is the design. The action is named and must be one of the
 * subject's own create or update actions for this register and schema; the
 * field must be a declared file field of it; the action's minTrust is
 * re-checked; ownership is proven the way the action writes; a create
 * action's window must still be open; the file must fit the field. Only then
 * is anything attached, and only after the attach is the reference written,
 * by the server, through the ownership-checking writer.
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
 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalFileFieldPolicy;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves the scoped upload into a declared file field.
 *
 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalSessionService::trustSatisfies,
 * the one trust ordering every portal gate shares.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- one collaborator per step
 * of the upload (who, which action, whose object, attach, write, record) plus
 * the framework attributes every portal endpoint carries; folding them into a
 * facade would hide which boundary each step crosses.
 */
class PortalFieldFileController extends Controller implements PortalProtected {
	/**
	 * HTTP status per policy refusal.
	 */
	private const REFUSAL_STATUS = [
		PortalFileFieldPolicy::ERROR_TOO_LARGE => Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
		PortalFileFieldPolicy::ERROR_TYPE_REFUSED => Http::STATUS_UNSUPPORTED_MEDIA_TYPE,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalContributionRegistry $registry The subject's contributions.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalObjectReader $reader The scoped single-object read.
	 * @param PortalObjectWriter $writer The ownership-checking writer.
	 * @param PortalFileWriter $fileWriter Attaches the file through OpenRegister.
	 * @param PortalFileFieldPolicy $policy The upload rules.
	 * @param AuditTrailService $auditor Records the write.
	 * @param LoggerInterface $logger Records the cause of a failed write.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalSessionService $session,
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly PortalFileWriter $fileWriter,
		private readonly PortalFileFieldPolicy $policy,
		private readonly AuditTrailService $auditor,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Upload one file into a declared file field of an owned object.
	 *
	 * Multipart part `file`; the action is named with `?action=`. Three steps,
	 * each of which can only refuse before the next one runs: authorise the
	 * subject, action, field and object; check the file; attach and write.
	 *
	 * @param string $register The register of the action.
	 * @param string $schema The schema of the action.
	 * @param string $id The object id (never trusted; ownership proven first).
	 * @param string $field The file field.
	 *
	 * @return JSONResponse `{file, field, value}`, or 400 / 401 / 403 / 404 / 409 / 413 / 415 / 502.
	 *
	 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function upload(string $register, string $schema, string $id, string $field): JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$target = ['register' => $register, 'schema' => $schema, 'id' => $id, 'field' => $field];
		$context = $this->authorise(subject: $subject, target: $target);
		if ($context instanceof JSONResponse) {
			return $context;
		}

		$upload = $this->checkedUpload(context: $context);
		if ($upload instanceof JSONResponse) {
			return $upload;
		}

		return $this->store(context: $context, upload: $upload);
	}//end upload()

	/**
	 * Everything the subject must be entitled to before a byte is read.
	 *
	 * The named action must be the subject's own create or update action for
	 * this register and schema, the field a declared file field of it, the
	 * subject's trust at least the action's minTrust (403 for all three). The
	 * object must be the subject's (one 404, no oracle), and a create action's
	 * window must still be open (403 `upload_window_closed`).
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array{register: string, schema: string, id: string, field: string} $target The route parameters.
	 *
	 * @return array<string, mixed>|JSONResponse The upload context, or the refusal.
	 *
	 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	private function authorise(array $subject, array $target): array|JSONResponse {
		$match = $this->authorisedAction(subject: $subject, register: $target['register'], schema: $target['schema']);
		$config = null;
		if ($match !== null) {
			$config = $this->policy->fileConfig(action: $match['action'], field: $target['field']);
		}

		if ($match === null
			|| $config === null
			|| PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($match['action']['minTrust'] ?? null)) === false
		) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$scope = $this->ownershipScope(action: $match['action'], app: $match['app'], subject: $subject);
		$owned = $this->ownedObject(target: $target, scope: $scope, subject: $subject);
		if ($scope === null || $owned === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->policy->windowOpen(actionType: (string)($match['action']['type'] ?? ''), row: $owned) === false) {
			return new JSONResponse(['error' => 'upload_window_closed'], Http::STATUS_FORBIDDEN);
		}

		return $target + ['subject' => $subject, 'config' => $config, 'scope' => $scope, 'current' => ($owned[$target['field']] ?? null)];
	}//end authorise()

	/**
	 * Read the file and check it fits the field, before anything is attached.
	 *
	 * @param array<string, mixed> $context The upload context from authorise().
	 *
	 * @return array{name: string, content: string, arrayProperty: bool|null}|JSONResponse
	 *
	 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	private function checkedUpload(array $context): array|JSONResponse {
		$upload = $this->policy->readUpload(request: $this->request);
		if ($upload === null) {
			return new JSONResponse(['error' => 'no_file'], Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->policy->refusal(config: $context['config'], fileName: $upload['name'], content: $upload['content']);
		if ($refusal !== null) {
			return new JSONResponse(['error' => $refusal], self::REFUSAL_STATUS[$refusal]);
		}

		$arrayProperty = $this->policy->isArrayProperty(schemaSlug: $context['schema'], field: $context['field']);
		if ($this->policy->hasRoom(config: $context['config'], current: $context['current'], arrayProperty: $arrayProperty) === false) {
			return new JSONResponse(['error' => 'too_many_files'], Http::STATUS_CONFLICT);
		}

		return $upload + ['arrayProperty' => $arrayProperty];
	}//end checkedUpload()

	/**
	 * Attach the file, then write its reference into the field.
	 *
	 * @param array<string, mixed> $context The upload context from authorise().
	 * @param array{name: string, content: string, arrayProperty: bool|null} $upload The checked file.
	 *
	 * @return JSONResponse `{file, field, value}`, or 502.
	 *
	 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
	 */
	private function store(array $context, array $upload): JSONResponse {
		$file = $this->fileWriter->attachFile(
			register: $context['register'],
			schema: $context['schema'],
			id: $context['id'],
			fileName: $upload['name'],
			content: $upload['content']
		);
		$fileId = '';
		if (is_array($file) === true && is_scalar($file['id'] ?? null) === true) {
			$fileId = (string)$file['id'];
		}

		if ($fileId === '') {
			return new JSONResponse(['error' => 'upload_failed'], Http::STATUS_BAD_GATEWAY);
		}

		$value = $this->policy->mergedValue(
			config: $context['config'],
			current: $context['current'],
			fileId: $fileId,
			arrayProperty: $upload['arrayProperty']
		);
		if ($this->writeReference(context: $context, value: $value) === false) {
			return new JSONResponse(['error' => 'write_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(
			[
				'file' => ['id' => $fileId, 'name' => (string)($file['name'] ?? $upload['name']), 'size' => ($file['size'] ?? null)],
				'field' => $context['field'],
				'value' => $value,
			]
		);
	}//end store()

	/**
	 * The subject's own create or update action named by `?action=`, with its
	 * contributing app, or null.
	 *
	 * The name is required: an upload never falls back to the first action
	 * for a schema, because two actions on one schema can declare different
	 * file fields.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $register The requested register.
	 * @param string $schema The requested schema.
	 *
	 * @return array{action: array<string, mixed>, app: string}|null
	 */
	private function authorisedAction(array $subject, string $register, string $schema): ?array {
		$actionId = (string)$this->request->getParam('action', '');
		if ($actionId === '') {
			return null;
		}

		foreach (($this->registry->aggregateFor($subject)['contributions'] ?? []) as $contribution) {
			foreach (($contribution['actions'] ?? []) as $action) {
				if ((string)($action['id'] ?? '') === $actionId
					&& in_array(($action['type'] ?? ''), ['create', 'update'], true) === true
					&& ($action['register'] ?? '') === $register
					&& ($action['schema'] ?? '') === $schema
				) {
					return ['action' => $action, 'app' => (string)($contribution['app'] ?? '')];
				}
			}
		}

		return null;
	}//end authorisedAction()

	/**
	 * The scope field and value the action writes, or null when a declared
	 * claim does not resolve.
	 *
	 * A create action stamps the subject's own `subjectRef` (ContributionController::create);
	 * an update action resolves its `scopeClaim` (ContributionController::update). The
	 * upload proves ownership with the value its action would have written.
	 *
	 * @param array<string, mixed> $action The matched action.
	 * @param string $app The contributing app.
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array{field: string, value: string}|null
	 */
	private function ownershipScope(array $action, string $app, array $subject): ?array {
		$value = (string)($subject['subjectRef'] ?? '');
		if (($action['type'] ?? '') === 'update') {
			$value = (string)$this->reader->resolveScopeValue(scopeClaim: (string)($action['scopeClaim'] ?? ''), contributingApp: $app, subject: $subject);
		}

		if ($value === '') {
			return null;
		}

		return ['field' => (string)($action['scopeField'] ?? 'subjectRef'), 'value' => $value];
	}//end ownershipScope()

	/**
	 * The object when the subject owns it, else null (one 404, no oracle).
	 *
	 * @param array{register: string, schema: string, id: string, field: string} $target The route parameters.
	 * @param array{field: string, value: string}|null $scope The ownership scope.
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ownedObject(array $target, ?array $scope, array $subject): ?array {
		if ($scope === null) {
			return null;
		}

		return $this->reader->readObject(
			register: $target['register'],
			schema: $target['schema'],
			scopeField: $scope['field'],
			subjectRef: $scope['value'],
			id: $target['id'],
			organisation: (string)($subject['organisation'] ?? '')
		);
	}//end ownedObject()

	/**
	 * Write the reference through the ownership-checking writer and record it.
	 *
	 * The writer re-verifies ownership before it saves. A throw from storage
	 * is logged, never returned: its message can name another tenant's data.
	 *
	 * @param array<string, mixed> $context The upload context from authorise().
	 * @param array<int, string>|string|null $value The field's new value.
	 *
	 * @return bool Whether the reference was written.
	 */
	private function writeReference(array $context, array|string|null $value): bool {
		$subject = $context['subject'];
		try {
			$updated = $this->writer->updateObject(
				register: $context['register'],
				schema: $context['schema'],
				scopeField: $context['scope']['field'],
				subjectRef: $context['scope']['value'],
				organisation: (string)($subject['organisation'] ?? ''),
				id: $context['id'],
				data: [$context['field'] => $value]
			);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: file field reference write failed: ' . $e->getMessage(), ['exception' => $e]);
			return false;
		}

		if ($updated === null) {
			return false;
		}

		$this->auditor->record(
			verb: 'update',
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			register: $context['register'],
			schema: $context['schema'],
			id: $context['id'],
			jti: (string)($subject['jti'] ?? '')
		);

		return true;
	}//end writeReference()
}//end class
