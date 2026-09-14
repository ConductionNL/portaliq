<?php

/**
 * Portaliq Citizen Case Controller
 *
 * The three acts a citizen may perform on their own case: correct an answer
 * they already gave, add the document that was missing, and read what the case
 * app calls the status in public. The task answer is the fourth act and lives
 * in PortalTaskProxyController, because a task is delivered and returned
 * through the task seam, not through the case row.
 *
 * The acts are separate on purpose (D2). An amendment, a document and a task
 * answer look alike to a database and nothing alike to a citizen or to the
 * law, so each has its own route, its own refusal and its own event.
 *
 * Nothing here decides what may be written. The writable set is resolved from
 * the case type on every request, and a write to a field outside it is refused
 * with the same sentence the citizen was shown.
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
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Contribution\CitizenWriteConfigNormaliser;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Event\PortalClientWriteEvent;
use OCA\Portaliq\Service\CitizenWritableSetResolver;
use OCA\Portaliq\Service\CitizenWriteRecorder;
use OCA\Portaliq\Service\CitizenWriteThrottle;
use OCA\Portaliq\Service\PortalFileReader;
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
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Serves and accepts the writes a citizen may make on their own case.
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess)             -- PortalSessionService::trustSatisfies
 * is the single trust comparator across every portal handler; calling it
 * statically is what keeps the ordering from forking.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)   -- one dependency per
 * distinct scoped capability (read/write/file/resolve/record/throttle),
 * ADR-022; a facade would hide which boundary each act crosses.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   -- see ExcessiveParameterList.
 */
class CitizenCaseController extends Controller implements PortalProtected {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalContributionRegistry $registry The contribution aggregator.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalObjectReader $reader Subject-scoped OpenRegister reader.
	 * @param PortalObjectWriter $writer Subject-scoped OpenRegister writer.
	 * @param PortalFileReader $fileReader Lists what is already on the case.
	 * @param PortalFileWriter $fileWriter Adds the citizen's document.
	 * @param CitizenWritableSetResolver $writableSet Reads the case type's flags.
	 * @param CitizenWriteRecorder $recorder Records and announces the write.
	 * @param CitizenWriteThrottle $throttle Counts writes per identity and per case.
	 * @param IL10N $l10n The sentences a refusal is given with.
	 * @param LoggerInterface $logger Records the cause of a translated failure.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalSessionService $session,
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly PortalFileReader $fileReader,
		private readonly PortalFileWriter $fileWriter,
		private readonly CitizenWritableSetResolver $writableSet,
		private readonly CitizenWriteRecorder $recorder,
		private readonly CitizenWriteThrottle $throttle,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The citizen's own case, with the writable set that governs it.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 *
	 * @return JSONResponse The case and its writable set, or a refusal.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $register, string $schema, string $id): JSONResponse {
		$context = $this->context(register: $register, schema: $schema, id: $id);
		if ($context instanceof JSONResponse) {
			return $context;
		}

		return new JSONResponse([
			'case' => $context['case'],
			'writableSet' => $context['set'],
			'documents' => $this->fileReader->listFiles(register: $register, schema: $schema, id: $id),
		]);
	}//end show()

	/**
	 * Amend the answers the citizen already gave, inside the declared window.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 *
	 * @return JSONResponse The amended case, or a refusal with a sentence.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function amend(string $register, string $schema, string $id): JSONResponse {
		$context = $this->context(register: $register, schema: $schema, id: $id);
		if ($context instanceof JSONResponse) {
			return $context;
		}

		$refusal = $this->guardWrite(context: $context, id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		$set = $context['set'];
		if (($set['window']['open'] ?? false) !== true) {
			return $this->refuse(
				message: (string)($set['window']['reason'] ?? ''),
				slug: 'amendment-window-closed',
				status: Http::STATUS_CONFLICT
			);
		}

		$submitted = $this->submittedFields(set: $set);
		if ($submitted instanceof JSONResponse) {
			return $submitted;
		}

		if ($submitted === []) {
			return $this->refuse(
				message: $this->l10n->t('There is nothing to change.'),
				slug: 'nothing-to-change',
				status: Http::STATUS_BAD_REQUEST
			);
		}

		return $this->applyAmendment(context: $context, register: $register, schema: $schema, id: $id, submitted: $submitted);
	}//end amend()

	/**
	 * Add a document to the running case, through the file surface the case app
	 * already declares. Nothing already on the case is replaced: a colliding
	 * name is given a suffix, so the municipality's own document survives a
	 * citizen uploading one that happens to be called the same.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 *
	 * @return JSONResponse The added document, or a refusal with a sentence.
	 *
	 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function addDocument(string $register, string $schema, string $id): JSONResponse {
		$context = $this->context(register: $register, schema: $schema, id: $id);
		if ($context instanceof JSONResponse) {
			return $context;
		}

		$refusal = $this->guardWrite(context: $context, id: $id);
		if ($refusal !== null) {
			return $refusal;
		}

		$set = $context['set'];
		if (($set['documents']['open'] ?? false) !== true) {
			return $this->refuse(
				message: (string)($set['documents']['reason'] ?? ''),
				slug: 'documents-closed',
				status: Http::STATUS_CONFLICT
			);
		}

		$upload = $this->readUploadedFile();
		if ($upload === null) {
			return $this->refuse(
				message: $this->l10n->t('There is no file to add.'),
				slug: 'no-file',
				status: Http::STATUS_BAD_REQUEST
			);
		}

		$attached = $this->fileWriter->attachFile(
			register: $register,
			schema: $schema,
			id: $id,
			fileName: $this->uniqueFileName(register: $register, schema: $schema, id: $id, fileName: $upload['name']),
			content: $upload['content']
		);
		if ($attached === null) {
			return $this->refuse(
				message: $this->l10n->t('The document could not be added. Please try again.'),
				slug: 'document-not-added',
				status: Http::STATUS_BAD_GATEWAY
			);
		}

		$this->recorder->announce(
			register: $register,
			schema: $schema,
			caseId: $id,
			act: PortalClientWriteEvent::ACT_DOCUMENT,
			fields: [(string)($attached['name'] ?? '')],
			subject: $context['subject'],
			action: $context['action'],
			occurredAt: $this->recorder->now()
		);

		return new JSONResponse(['document' => $attached]);
	}//end addDocument()

	/**
	 * Resolve the subject, the citizen write action, the case and its writable
	 * set, or the refusal that stops all three acts at the same gate.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 *
	 * @return array<string, mixed>|JSONResponse
	 */
	private function context(string $register, string $schema, string $id): array|JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$match = $this->citizenWriteAction(subject: $subject, register: $register, schema: $schema);
		if ($match === null) {
			return $this->refuse(
				message: $this->l10n->t('This case cannot be changed from the portal.'),
				slug: 'portal-writes-not-declared',
				status: Http::STATUS_FORBIDDEN
			);
		}

		$action = $match['action'];
		if (PortalSessionService::trustSatisfies(($subject['trust'] ?? ''), ($action['minTrust'] ?? null)) === false) {
			return $this->refuse(
				message: $this->l10n->t('This case cannot be changed from the portal.'),
				slug: 'portal-writes-not-declared',
				status: Http::STATUS_FORBIDDEN
			);
		}

		// The ownership boundary. A case that is not the citizen's and a case
		// that does not exist answer identically, so the portal never confirms
		// that someone else's case number is real.
		$case = $this->reader->readObject(
			register: $register,
			schema: $schema,
			scopeField: (string)($action['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			id: $id,
			organisation: (string)($subject['organisation'] ?? ''),
			scopeClaim: (string)($action['scopeClaim'] ?? ''),
			contributingApp: $match['app'],
			audience: (string)($subject['audience'] ?? '')
		);
		if ($case === null) {
			return $this->refuse(
				message: $this->l10n->t('This case is not yours.'),
				slug: 'case-not-yours',
				status: Http::STATUS_FORBIDDEN
			);
		}

		return [
			'subject' => $subject,
			'action' => $action,
			'app' => $match['app'],
			'case' => $case,
			'set' => $this->writableSet->resolve(
				action: $action,
				case: $case,
				audience: (string)($subject['audience'] ?? '')
			),
		];
	}//end context()

	/**
	 * The guard both write acts share: the throttle, counted per identity and
	 * per case rather than per address.
	 *
	 * @param array<string, mixed> $context The resolved context.
	 * @param string $id The case id.
	 *
	 * @return JSONResponse|null The refusal, or null to proceed.
	 */
	private function guardWrite(array $context, string $id): ?JSONResponse {
		$allowed = $this->throttle->allow(
			subjectRef: (string)($context['subject']['subjectRef'] ?? ''),
			caseId: $id
		);
		if ($allowed === true) {
			return null;
		}

		return $this->refuse(
			message: $this->l10n->t('You have made a lot of changes in a short time. Please try again later.'),
			slug: 'too-many-writes',
			status: Http::STATUS_TOO_MANY_REQUESTS
		);
	}//end guardWrite()

	/**
	 * The fields the request names, each checked against the writable set, or
	 * the refusal naming the first field that is not open and why.
	 *
	 * @param array<string, mixed> $set The resolved writable set.
	 *
	 * @return array<string, mixed>|JSONResponse
	 */
	private function submittedFields(array $set): array|JSONResponse {
		$body = $this->request->getParam('fields');
		if (is_array($body) === false) {
			return [];
		}

		$submitted = [];
		foreach ($body as $field => $value) {
			$field = (string)$field;
			if ($this->writableSet->isWritable(set: $set, field: $field) === false) {
				$reason = (string)($set['fields'][$field]['reason'] ?? '');
				if ($reason === '') {
					$reason = $this->l10n->t('This answer cannot be changed from the portal.');
				}

				return $this->refuse(message: $reason, slug: 'field-not-writable', status: Http::STATUS_UNPROCESSABLE_ENTITY);
			}

			$submitted[$field] = $value;
		}//end foreach

		return $submitted;
	}//end submittedFields()

	/**
	 * Write the amendment, append the record naming the citizen, and announce
	 * it. The record carries both answers, so a case worker sees what the
	 * answer was before the correction and what it is now.
	 *
	 * @param array<string, mixed> $context The resolved context.
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 * @param array<string, mixed> $submitted The accepted fields.
	 *
	 * @return JSONResponse
	 */
	private function applyAmendment(
		array $context,
		string $register,
		string $schema,
		string $id,
		array $submitted,
	): JSONResponse {
		$action = $context['action'];
		$config = (array)($action[CitizenWriteConfigNormaliser::KEY] ?? []);
		$recordField = (string)($config['recordField'] ?? 'portalWrites');
		$occurredAt = $this->recorder->now();

		$changes = [];
		foreach ($submitted as $field => $value) {
			$changes[$field] = ['from' => ($context['case'][$field] ?? null), 'to' => $value];
		}

		$data = $submitted;
		$data[$recordField] = $this->recorder->append(
			existing: ($context['case'][$recordField] ?? null),
			record: $this->recorder->buildRecord(
				act: PortalClientWriteEvent::ACT_AMENDMENT,
				subject: $context['subject'],
				action: $action,
				changes: $changes,
				occurredAt: $occurredAt
			)
		);

		try {
			$updated = $this->writer->updateObject(
				register: $register,
				schema: $schema,
				scopeField: (string)($action['scopeField'] ?? 'subjectRef'),
				subjectRef: (string)($context['subject']['subjectRef'] ?? ''),
				organisation: (string)($context['subject']['organisation'] ?? ''),
				id: $id,
				data: $data
			);
		} catch (\Throwable $e) {
			$this->logger->error('Citizen amendment failed: ' . $e->getMessage(), ['exception' => $e]);
			return $this->refuse(
				message: $this->l10n->t('The change could not be saved. Please try again.'),
				slug: 'amendment-not-saved',
				status: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		if ($updated === null) {
			return $this->refuse(
				message: $this->l10n->t('This case is not yours.'),
				slug: 'case-not-yours',
				status: Http::STATUS_FORBIDDEN
			);
		}

		$this->recorder->announce(
			register: $register,
			schema: $schema,
			caseId: $id,
			act: PortalClientWriteEvent::ACT_AMENDMENT,
			fields: array_map(strval(...), array_keys($submitted)),
			subject: $context['subject'],
			action: $action,
			occurredAt: $occurredAt
		);

		return new JSONResponse(['case' => $updated]);
	}//end applyAmendment()

	/**
	 * Find the `type: update` action for this register and schema that carries
	 * a citizen write declaration, inside the subject's OWN aggregate. An
	 * action without the declaration is not a citizen write surface, whatever
	 * else it permits.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $register The requested register.
	 * @param string $schema The requested schema.
	 *
	 * @return array{action: array<string, mixed>, app: string}|null
	 */
	private function citizenWriteAction(array $subject, string $register, string $schema): ?array {
		$aggregate = $this->registry->aggregateFor($subject);
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			foreach (($contribution['actions'] ?? []) as $action) {
				if (($action['type'] ?? '') !== 'update'
					|| ($action['register'] ?? '') !== $register
					|| ($action['schema'] ?? '') !== $schema
					|| is_array(($action[CitizenWriteConfigNormaliser::KEY] ?? null)) === false
				) {
					continue;
				}

				return ['action' => $action, 'app' => (string)($contribution['app'] ?? '')];
			}
		}

		return null;
	}//end citizenWriteAction()

	/**
	 * A name no document on the case already uses, so an upload adds rather
	 * than replaces.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 * @param string $fileName The sanitised upload name.
	 *
	 * @return string
	 */
	private function uniqueFileName(string $register, string $schema, string $id, string $fileName): string {
		$taken = [];
		foreach ($this->fileReader->listFiles(register: $register, schema: $schema, id: $id) as $file) {
			$name = ($file['name'] ?? null);
			if (is_string($name) === true && $name !== '') {
				$taken[] = $name;
			}
		}

		if (in_array($fileName, $taken, true) === false) {
			return $fileName;
		}

		$extension = pathinfo($fileName, PATHINFO_EXTENSION);
		$stem = pathinfo($fileName, PATHINFO_FILENAME);
		$suffix = ($extension === '') ? '' : ('.' . $extension);
		$counter = 2;
		while (in_array($stem . '-' . $counter . $suffix, $taken, true) === true) {
			$counter++;
		}

		return $stem . '-' . $counter . $suffix;
	}//end uniqueFileName()

	/**
	 * Read the multipart upload into a {name, content} pair, or null when
	 * there is nothing usable. The client's path is never trusted.
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
	 * A refusal the citizen can read: one sentence, plus a slug the portal
	 * matches on so it can say the same thing in its own language (ADR-050).
	 *
	 * @param string $message The sentence to show.
	 * @param string $slug The machine-readable refusal.
	 * @param int $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function refuse(string $message, string $slug, int $status): JSONResponse {
		return new JSONResponse(['message' => $message, 'error' => $slug], $status);
	}//end refuse()
}//end class
