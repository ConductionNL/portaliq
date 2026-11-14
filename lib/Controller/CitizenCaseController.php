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
 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Contribution\CitizenWriteActionFinder;
use OCA\Portaliq\Event\PortalClientWriteEvent;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\CitizenCaseDocumentsInterface;
use OCA\Portaliq\Kernel\Contracts\CitizenDocumentUploadInterface;
use OCA\Portaliq\Kernel\Contracts\CitizenWritableSetResolverInterface;
use OCA\Portaliq\Kernel\Contracts\CitizenWriteConfigNormaliserInterface;
use OCA\Portaliq\Kernel\Contracts\CitizenWriteRecorderInterface;
use OCA\Portaliq\Kernel\Contracts\CitizenWriteThrottleInterface;
use OCA\Portaliq\Kernel\Contracts\MandatedCaseReaderInterface;
use OCA\Portaliq\Kernel\Contracts\PortalBranchScopeInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFileReaderInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFileWriterInterface;
use OCA\Portaliq\Kernel\Contracts\PortalMandateServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalObjectReaderInterface;
use OCA\Portaliq\Kernel\Contracts\PortalObjectWriterInterface;
use OCA\Portaliq\Kernel\Contracts\PortalPartyTreeResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Kernel\Support\PortalBranchScope;
use OCA\Portaliq\Service\CitizenCaseProjection;
use OCA\Portaliq\Kernel\Support\SessionTrust;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use OCA\Portaliq\Kernel\Contracts\MandatePartiesInterface;

/**
 * Serves and accepts the writes a citizen may make on their own case.
 *
 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
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
	 * The field names a citizen write declaration may configure, with the
	 * name used when it does not.
	 *
	 * @var array<string, string>
	 */
	private const WRITE_CONFIG_DEFAULTS = [
		'statusField' => 'status',
		'recordField' => 'portalWrites',
	];
	/**
	 * The lazily built action lookup, see writeActions().
	 *
	 * @var CitizenWriteActionFinder|null
	 */
	private ?CitizenWriteActionFinder $writeActions = null;


	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalContributionRegistryInterface $registry The contribution aggregator.
	 * @param PortalSessionServiceInterface $session Resolves the subject from the bearer.
	 * @param PortalObjectReaderInterface $reader Subject-scoped OpenRegister reader.
	 * @param PortalObjectWriterInterface $writer Subject-scoped OpenRegister writer.
	 * @param PortalFileReaderInterface $fileReader Lists what is already on the case.
	 * @param PortalFileWriterInterface $fileWriter Adds the citizen's document.
	 * @param CitizenWritableSetResolverInterface $writableSet Reads the case type's flags.
	 * @param CitizenWriteRecorderInterface $recorder Records and announces the write.
	 * @param CitizenWriteThrottleInterface $throttle Counts writes per identity and per case.
	 * @param CitizenDocumentUploadInterface $upload Reads and names the citizen's document.
	 * @param PortalMandateServiceInterface $mandates The mandates the identity holds.
	 * @param PortalPartyTreeResolverInterface $tree How far a mandate reaches.
	 * @param IL10N $l10n The sentences a refusal is given with.
	 * @param LoggerInterface $logger Records the cause of a translated failure.
	 * @param CitizenCaseDocumentsInterface $documents Lists and opens the documents on the case.
	 * @param MandatePartiesInterface $mandateParties The MandateParties helper.
	 * @param MandatedCaseReaderInterface|null $mandatedCases Reads a case listed under a
	 *                                               mandate (cases-my-cases-page).
	 *                                               Absent opens only own cases.
	 * @param PortalBranchScopeInterface $branches The branch filter of signin-eherkenning-branch.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistryInterface $registry,
		private readonly PortalSessionServiceInterface $session,
		private readonly PortalObjectReaderInterface $reader,
		private readonly PortalObjectWriterInterface $writer,
		private readonly PortalFileReaderInterface $fileReader,
		private readonly PortalFileWriterInterface $fileWriter,
		private readonly CitizenWritableSetResolverInterface $writableSet,
		private readonly CitizenWriteRecorderInterface $recorder,
		private readonly CitizenWriteThrottleInterface $throttle,
		private readonly CitizenDocumentUploadInterface $upload,
		private readonly PortalMandateServiceInterface $mandates,
		private readonly PortalPartyTreeResolverInterface $tree,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly CitizenCaseDocumentsInterface $documents,
		private readonly MandatePartiesInterface $mandateParties,
		private readonly ?MandatedCaseReaderInterface $mandatedCases = null,
		private readonly PortalBranchScopeInterface $branches = new PortalBranchScope(),
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $register, string $schema, string $id): JSONResponse {
		$context = $this->context(register: $register, schema: $schema, id: $id);
		if ($context instanceof JSONResponse) {
			// Not their own case: it may be one they see under the mandate
			// they chose (cases-my-cases-page REQ-CMC-004), read-only.
			// The case comes from the same reader that lists the mandated
			// cases on "My cases", so this screen never opens a case the list
			// would not show; every write still checks ownership.
			return ($this->mandatedCases?->screenOr(
				refusal: $context,
				subject: $this->session->resolveFromBearer($this->request->getHeader('Authorization')),
				mandateId: (string)$this->request->getParam('mandate', ''),
				target: ['register' => $register, 'schema' => $schema, 'id' => $id],
				l10n: $this->l10n
			) ?? $context);
		}

		return new JSONResponse([
			'case' => (new CitizenCaseProjection(logger: $this->logger))->visible(context: $context, case: $context['case']),
			'writableSet' => $context['set'],
			// What the portal may offer about ending this request, resolved
			// from the case type rather than from any list the portal keeps
			// (withdrawing-your-own-case REQ-WOC-001).
			'withdrawal' => $this->writableSet->withdrawal(
				action: $context['action'],
				case: $context['case'],
				closedField: (string)($context['closedField'] ?? '')
			),
			'documents' => $this->documents->listFor(context: $context, register: $register, schema: $schema, id: $id),
			'documentsLabel' => (string)($context['documents']['label'] ?? ''),
			// Which projected fields hold the public outcome and its explanation
			// (case-outcome-on-the-case-page REQ-COC-001); the page shows them
			// only once the case has ended.
			'outcome' => ($context['outcome'] ?? null),
		]);
	}//end show()

	/**
	 * Open one document the case screen listed. The id picks among what this
	 * case lists now; nothing in the request says where a file lives. A
	 * foreign case, a missing one and an unlisted id answer the same 404
	 * (cases-documents-on-the-case, REQ-CDC-002).
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 * @param string $documentId The listed entry's id.
	 *
	 * @return Response The file, or 401 / 404.
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-every-listed-document-opens-from-the-case-screen-req-cdc-002
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function document(string $register, string $schema, string $id, string $documentId): Response {
		return $this->documents->open(
			context: $this->context(register: $register, schema: $schema, id: $id),
			register: $register,
			schema: $schema,
			id: $id,
			documentId: $documentId
		);
	}//end document()

	/**
	 * Amend the answers the citizen already gave, inside the declared window.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 *
	 * @return JSONResponse The amended case, or a refusal with a sentence.
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function amend(string $register, string $schema, string $id): JSONResponse {
		$write = $this->writableCase(target: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($write instanceof JSONResponse) {
			return $write;
		}

		$refusal = $this->amendmentWindowRefusal(set: $write->context['set']);
		if ($refusal !== null) {
			return $refusal;
		}

		$submitted = $this->changedFields(set: $write->context['set']);
		if ($submitted instanceof JSONResponse) {
			return $submitted;
		}

		return $this->applyAmendment(write: $write, submitted: $submitted);
	}//end amend()

	/**
	 * The case the route names, once the citizen may write on it: the
	 * resolved context, past the throttle. Else the refusal.
	 *
	 * @param array{register: string, schema: string, id: string} $target The case the route names.
	 *
	 * @return CaseWriteRun|JSONResponse
	 */
	private function writableCase(array $target): CaseWriteRun|JSONResponse {
		$context = $this->context(register: $target['register'], schema: $target['schema'], id: $target['id']);
		if ($context instanceof JSONResponse) {
			return $context;
		}

		$refusal = $this->guardWrite(context: $context, id: $target['id']);
		if ($refusal !== null) {
			return $refusal;
		}

		return new CaseWriteRun(context: $context, target: $target);
	}//end writableCase()

	/**
	 * The refusal when the amendment window is closed, else null.
	 *
	 * @param array<string, mixed> $set The resolved writable set.
	 *
	 * @return JSONResponse|null
	 */
	private function amendmentWindowRefusal(array $set): ?JSONResponse {
		if (($set['window']['open'] ?? false) === true) {
			return null;
		}

		return $this->refuse(
			message: (string)($set['window']['reason'] ?? ''),
			slug: 'amendment-window-closed',
			status: Http::STATUS_CONFLICT
		);
	}//end amendmentWindowRefusal()

	/**
	 * The fields the request changes, or the refusal when one is not writable
	 * or none is named.
	 *
	 * @param array<string, mixed> $set The resolved writable set.
	 *
	 * @return array<string, mixed>|JSONResponse
	 */
	private function changedFields(array $set): array|JSONResponse {
		$submitted = $this->submittedFields(set: $set);
		if ($submitted !== []) {
			return $submitted;
		}

		return $this->refuse(
			message: $this->l10n->t('There is nothing to change.'),
			slug: 'nothing-to-change',
			status: Http::STATUS_BAD_REQUEST
		);
	}//end changedFields()

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
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function addDocument(string $register, string $schema, string $id): JSONResponse {
		$write = $this->writableCase(target: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($write instanceof JSONResponse) {
			return $write;
		}

		$refusal = $this->documentsClosedRefusal(set: $write->context['set']);
		if ($refusal !== null) {
			return $refusal;
		}

		$upload = $this->uploadedDocument();
		if ($upload instanceof JSONResponse) {
			return $upload;
		}

		return $this->attachDocument(write: $write, upload: $upload);
	}//end addDocument()

	/**
	 * The refusal when the case takes no documents now, else null.
	 *
	 * @param array<string, mixed> $set The resolved writable set.
	 *
	 * @return JSONResponse|null
	 */
	private function documentsClosedRefusal(array $set): ?JSONResponse {
		if (($set['documents']['open'] ?? false) === true) {
			return null;
		}

		return $this->refuse(
			message: (string)($set['documents']['reason'] ?? ''),
			slug: 'documents-closed',
			status: Http::STATUS_CONFLICT
		);
	}//end documentsClosedRefusal()

	/**
	 * The uploaded `file`, or the refusal when there is none.
	 *
	 * @return array{name: string, content: string}|JSONResponse
	 */
	private function uploadedDocument(): array|JSONResponse {
		$upload = $this->upload->read($this->request->getUploadedFile('file'));
		if ($upload !== null) {
			return $upload;
		}

		return $this->refuse(
			message: $this->l10n->t('There is no file to add.'),
			slug: 'no-file',
			status: Http::STATUS_BAD_REQUEST
		);
	}//end uploadedDocument()

	/**
	 * Attach the upload to the case and announce it.
	 *
	 * @param CaseWriteRun                        $write  The write, on a case that takes documents.
	 * @param array{name: string, content: string} $upload The uploaded file.
	 *
	 * @return JSONResponse The added document, or the refusal.
	 */
	private function attachDocument(CaseWriteRun $write, array $upload): JSONResponse {
		$attached = $this->attachedUpload(write: $write, upload: $upload);
		if ($attached === null) {
			return $this->refuse(
				message: $this->l10n->t('The document could not be added. Please try again.'),
				slug: 'document-not-added',
				status: Http::STATUS_BAD_GATEWAY
			);
		}

		$write->act        = PortalClientWriteEvent::ACT_DOCUMENT;
		$write->occurredAt = $this->recorder->now();
		$this->announceWrite(write: $write, fields: [(string)($attached['name'] ?? '')]);

		return new JSONResponse(['document' => $attached]);
	}//end attachDocument()

	/**
	 * Attach the upload under a name nothing on the case already has, or
	 * null when the case app does not take it.
	 *
	 * @param CaseWriteRun                        $write  The write, on a case that takes documents.
	 * @param array{name: string, content: string} $upload The uploaded file.
	 *
	 * @return array<string, mixed>|null
	 */
	private function attachedUpload(CaseWriteRun $write, array $upload): ?array {
		return $this->fileWriter->attachFile(
			register: $write->register,
			schema: $write->schema,
			id: $write->id,
			fileName: $this->upload->uniqueName(
				existing: $this->fileReader->listFiles(register: $write->register, schema: $write->schema, id: $write->id),
				fileName: $upload['name']
			),
			content: $upload['content'],
			// Tagged, so the case screen lists it under "Sent by you" and
			// nothing else from the folder (cases-documents-on-the-case).
			tags: [PortalFileWriterInterface::TAG_FROM_APPLICANT]
		);
	}//end attachedUpload()

	/**
	 * Withdraw the citizen's own request, where the case type allows it.
	 *
	 * Nothing about the outcome comes from the request: the status is the one
	 * the case type declares, the confirmation is the client's own step, and a
	 * request already on the declared status is refused rather than withdrawn
	 * twice. A reason travels along when the applicant gave one.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $id The case id.
	 *
	 * @return JSONResponse The withdrawn case, or a refusal with a sentence.
	 *
	 * @spec openspec/specs/withdrawing-your-own-case/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function withdraw(string $register, string $schema, string $id): JSONResponse {
		$write = $this->writableCase(target: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($write instanceof JSONResponse) {
			return $write;
		}

		$withdrawal = $this->openWithdrawal(context: $write->context);
		if ($withdrawal instanceof JSONResponse) {
			return $withdrawal;
		}

		return $this->applyWithdrawal(write: $write, withdrawal: $withdrawal);
	}//end withdraw()

	/**
	 * The case's withdrawal state when a withdrawal is open, else the refusal.
	 *
	 * @param array<string, mixed> $context The resolved context.
	 *
	 * @return array<string, mixed>|JSONResponse
	 */
	private function openWithdrawal(array $context): array|JSONResponse {
		$withdrawal = $this->writableSet->withdrawal(
			action: $context['action'],
			case: $context['case'],
			closedField: (string)($context['closedField'] ?? '')
		);
		if (($withdrawal['open'] ?? false) === true) {
			return $withdrawal;
		}

		return $this->refuse(
			message: (string)($withdrawal['reason'] ?? ''),
			slug: 'withdrawal-not-open',
			status: Http::STATUS_CONFLICT
		);
	}//end openWithdrawal()

	/**
	 * Write the withdrawal, record it beside the answers and announce it.
	 *
	 * @param CaseWriteRun         $write      The write, on a case that may be withdrawn.
	 * @param array<string, mixed> $withdrawal The resolved withdrawal state.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/withdrawing-your-own-case/spec.md
	 */
	private function applyWithdrawal(CaseWriteRun $write, array $withdrawal): JSONResponse {
		$change = $this->startWithdrawal(write: $write, withdrawal: $withdrawal);
		$data   = $this->withdrawalData(write: $write, withdrawal: $change);
		try {
			$updated = $this->updateCase(write: $write, data: $data);
		} catch (\Throwable $e) {
			return $this->withdrawalNotSaved(error: $e);
		}

		if ($updated === null) {
			return $this->caseNotYours();
		}

		$this->announceWithdrawal(write: $write, withdrawal: $change);

		return $this->withdrawnAnswer(write: $write, updated: $updated);
	}//end applyWithdrawal()

	/**
	 * Start the withdrawal now: the status the case type declares and the
	 * reason the applicant gave.
	 *
	 * @param CaseWriteRun         $write      The write, on a case that may be withdrawn.
	 * @param array<string, mixed> $withdrawal The resolved withdrawal state.
	 *
	 * @return CaseWithdrawal
	 */
	private function startWithdrawal(CaseWriteRun $write, array $withdrawal): CaseWithdrawal {
		$write->act        = 'withdrawal';
		$write->occurredAt = $this->recorder->now();
		return new CaseWithdrawal(
			status: (string)$withdrawal['targetStatus'],
			reason: trim((string)$this->request->getParam('reason', ''))
		);
	}//end startWithdrawal()

	/**
	 * What a withdrawal writes: the declared status, the moment and the
	 * reason, then the record of the change beside the answers.
	 *
	 * @param CaseWriteRun   $write      The withdrawal being written.
	 * @param CaseWithdrawal $withdrawal Its status and reason.
	 *
	 * @return array<string, mixed>
	 */
	private function withdrawalData(CaseWriteRun $write, CaseWithdrawal $withdrawal): array {
		$statusField = $this->writeConfigField(write: $write, key: 'statusField');
		// The status is the case app's, whatever the body says: the request's
		// own `status` is never read here, so there is nothing to tamper with.
		$data = [
			$statusField => $withdrawal->status,
			'withdrawnAt' => $write->occurredAt,
			'withdrawalReason' => $withdrawal->reason,
		];
		$data[$this->writeConfigField(write: $write, key: 'recordField')] = $this->writeRecord(
			write: $write,
			changes: $this->withdrawalChanges(write: $write, withdrawal: $withdrawal)
		);

		return $data;
	}//end withdrawalData()

	/**
	 * What a withdrawal changes: the status, from the case's own to the
	 * declared one, and the reason.
	 *
	 * @param CaseWriteRun   $write      The withdrawal being written.
	 * @param CaseWithdrawal $withdrawal Its status and reason.
	 *
	 * @return array<string, array{from: mixed, to: mixed}>
	 */
	private function withdrawalChanges(CaseWriteRun $write, CaseWithdrawal $withdrawal): array {
		$statusField = $this->writeConfigField(write: $write, key: 'statusField');
		return [
			$statusField => ['from' => ($write->context['case'][$statusField] ?? null), 'to' => $withdrawal->status],
			'reason' => ['from' => null, 'to' => $withdrawal->reason],
		];
	}//end withdrawalChanges()

	/**
	 * The refusal for a withdrawal the store did not save, logged.
	 *
	 * @param \Throwable $error Why it was not saved.
	 *
	 * @return JSONResponse
	 */
	private function withdrawalNotSaved(\Throwable $error): JSONResponse {
		$this->logger->error('Citizen withdrawal failed: ' . $error->getMessage(), ['exception' => $error]);
		return $this->refuse(
			message: $this->l10n->t('The request could not be withdrawn. Please try again.'),
			slug: 'withdrawal-not-saved',
			status: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end withdrawalNotSaved()

	/**
	 * Raise the withdrawal's own event.
	 *
	 * @param CaseWriteRun   $write      The withdrawal written.
	 * @param CaseWithdrawal $withdrawal Its status and reason.
	 *
	 * @return void
	 */
	private function announceWithdrawal(CaseWriteRun $write, CaseWithdrawal $withdrawal): void {
		$this->recorder->announceWithdrawal(
			register: $write->register,
			schema: $write->schema,
			caseId: $write->id,
			status: $withdrawal->status,
			reason: $withdrawal->reason,
			subject: $write->context['subject'],
			action: $write->context['action'],
			occurredAt: $write->occurredAt
		);
	}//end announceWithdrawal()

	/**
	 * The withdrawn case and its withdrawal state. Nothing is deleted and no
	 * undo is offered: the answers stay readable, with the withdrawal beside
	 * them.
	 *
	 * @param CaseWriteRun         $write   The withdrawal written.
	 * @param array<string, mixed> $updated The case as saved.
	 *
	 * @return JSONResponse
	 */
	private function withdrawnAnswer(CaseWriteRun $write, array $updated): JSONResponse {
		$context = $write->context;
		return new JSONResponse([
			'case' => (new CitizenCaseProjection(logger: $this->logger))->visible(context: $context, case: $updated),
			'withdrawal' => $this->writableSet->withdrawal(action: $context['action'], case: $updated, closedField: (string)($context['closedField'] ?? '')),
		]);
	}//end withdrawnAnswer()

	/**
	 * A field name the citizen write declaration configures, or its default.
	 *
	 * @param CaseWriteRun $write The write.
	 * @param string       $key   `statusField` or `recordField`.
	 *
	 * @return string
	 */
	private function writeConfigField(CaseWriteRun $write, string $key): string {
		$config = (array)($write->context['action'][CitizenWriteConfigNormaliserInterface::KEY] ?? []);
		return (string)($config[$key] ?? self::WRITE_CONFIG_DEFAULTS[$key]);
	}//end writeConfigField()

	/**
	 * The case's write history with the record of this write appended,
	 * naming the citizen and both answers.
	 *
	 * @param CaseWriteRun                                      $write   The write.
	 * @param array<string, array{from: mixed, to: mixed}> $changes What changed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function writeRecord(CaseWriteRun $write, array $changes): array {
		$recordField = $this->writeConfigField(write: $write, key: 'recordField');
		return $this->recorder->append(
			existing: ($write->context['case'][$recordField] ?? null),
			record: $this->recorder->buildRecord(
				act: $write->act,
				subject: $write->context['subject'],
				action: $write->context['action'],
				changes: $changes,
				occurredAt: $write->occurredAt
			)
		);
	}//end writeRecord()

	/**
	 * Save the write on the case under the action's scope. Null when the
	 * scoped write finds no case.
	 *
	 * @param CaseWriteRun         $write The write.
	 * @param array<string, mixed> $data  What to write.
	 *
	 * @return array<string, mixed>|null
	 */
	private function updateCase(CaseWriteRun $write, array $data): ?array {
		$subject = $write->context['subject'];
		return $this->writer->updateObject(
			register: $write->register,
			schema: $write->schema,
			scopeField: (string)($write->context['action']['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			id: $write->id,
			data: $data
		);
	}//end updateCase()

	/**
	 * The refusal for a case that is not the citizen's.
	 *
	 * @return JSONResponse
	 */
	private function caseNotYours(): JSONResponse {
		return $this->refuse(
			message: $this->l10n->t('This case is not yours.'),
			slug: 'case-not-yours',
			status: Http::STATUS_FORBIDDEN
		);
	}//end caseNotYours()

	/**
	 * Raise the citizen write event naming the fields or file written.
	 *
	 * @param CaseWriteRun      $write  The write.
	 * @param array<int, string> $fields The fields or file written.
	 *
	 * @return void
	 */
	private function announceWrite(CaseWriteRun $write, array $fields): void {
		$this->recorder->announce(
			register: $write->register,
			schema: $write->schema,
			caseId: $write->id,
			act: $write->act,
			fields: $fields,
			subject: $write->context['subject'],
			action: $write->context['action'],
			occurredAt: $write->occurredAt
		);
	}//end announceWrite()

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

		// Who this write is made for, when the citizen is acting under a
		// mandate rather than for themselves. Resolved here, from the mandate
		// record and the party tree, so the request can name an entity but
		// never grant itself one (REQ-PTV-006).
		$subject = $this->actingAs(subject: $subject);

		$match = $this->writableMatch(subject: $subject, register: $register, schema: $schema);
		if ($match instanceof JSONResponse) {
			return $match;
		}

		$action = $match['action'];

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
		// Change signin-eherkenning-branch D2: a branch session changes only its
		// branch's cases, and answers the same as for a case not its own.
		if ($case === null || $this->branches->admits(subject: $subject, collection: $action, row: $case) === false) {
			return $this->refuse(
				message: $this->l10n->t('This case is not yours.'),
				slug: 'case-not-yours',
				status: Http::STATUS_FORBIDDEN
			);
		}

		return $this->writeContext(subject: $subject, match: $match, case: $case);
	}//end context()

	/**
	 * The write context of an owned case: the subject, the matched action and
	 * app, the case, and the fields the action may set on it.
	 *
	 * @param array<string,mixed> $subject The session subject.
	 * @param array<string,mixed> $match   The matched write action.
	 * @param array<string,mixed> $case    The owned case.
	 *
	 * @return array<string,mixed>
	 */
	private function writeContext(array $subject, array $match, array $case): array {
		$action = $match['action'];

		return [
			'subject' => $subject,
			'action' => $action,
			'app' => $match['app'],
			'filesDownload' => $match['filesDownload'],
			'documents' => ($match['documents'] ?? null),
			'fields' => ($match['fields'] ?? null),
			'closedField' => (string)($match['closedField'] ?? ''),
			'outcome' => ($match['outcome'] ?? null),
			'case' => $case,
			'set' => $this->writableSet->resolve(
				action: $action,
				case: $case,
				audience: (string)($subject['audience'] ?? ''),
				closedField: (string)($match['closedField'] ?? '')
			),
		];
	}//end writeContext()

	/**
	 * The portal write action declared for this collection whose trust level
	 * the subject meets, or the 403 refusal.
	 *
	 * @param array<string,mixed> $subject  The session subject.
	 * @param string              $register The register.
	 * @param string              $schema   The schema.
	 *
	 * @return array<string,mixed>|JSONResponse
	 */
	private function writableMatch(array $subject, string $register, string $schema): array|JSONResponse {
		$match = $this->writeActions()->forSubject(subject: $subject, register: $register, schema: $schema);
		if ($match === null) {
			return $this->refuse(
				message: $this->l10n->t('This case cannot be changed from the portal.'),
				slug: 'portal-writes-not-declared',
				status: Http::STATUS_FORBIDDEN
			);
		}

		$action = $match['action'];
		if (SessionTrust::trustSatisfies(($subject['trust'] ?? ''), ($action['minTrust'] ?? null)) === false) {
			return $this->refuse(
				message: $this->l10n->t('This case cannot be changed from the portal.'),
				slug: 'portal-writes-not-declared',
				status: Http::STATUS_FORBIDDEN
			);
		}

		return $match;
	}//end writableMatch()

	/**
	 * The guard both write acts share: the throttle, counted per identity and
	 * per case rather than per address.
	 *
	 * @param array<string, mixed> $context The resolved context.
	 * @param string $id The case id.
	 *
	 * @return JSONResponse|null The refusal, or null to proceed.
	 *
	 * @spec openspec/changes/citizen-case-write-limit/specs/citizen-writes-on-their-own-case/spec.md#requirement-a-residents-writes-on-her-own-case-are-limited-per-case-and-per-identity-req-cwl-001
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
				return $this->fieldNotWritable(set: $set, field: $field);
			}

			$submitted[$field] = $value;
		}

		return $submitted;
	}//end submittedFields()

	/**
	 * The refusal naming a field that is not open, with the writable set's
	 * reason or the general one.
	 *
	 * @param array<string, mixed> $set   The resolved writable set.
	 * @param string               $field The field the request names.
	 *
	 * @return JSONResponse
	 */
	private function fieldNotWritable(array $set, string $field): JSONResponse {
		$reason = (string)($set['fields'][$field]['reason'] ?? '');
		if ($reason === '') {
			$reason = $this->l10n->t('This answer cannot be changed from the portal.');
		}

		return $this->refuse(message: $reason, slug: 'field-not-writable', status: Http::STATUS_UNPROCESSABLE_ENTITY);
	}//end fieldNotWritable()

	/**
	 * Write the amendment, append the record naming the citizen, and announce
	 * it. The record carries both answers, so a case worker sees what the
	 * answer was before the correction and what it is now.
	 *
	 * @param CaseWriteRun         $write     The write, inside the amendment window.
	 * @param array<string, mixed> $submitted The accepted fields.
	 *
	 * @return JSONResponse
	 */
	private function applyAmendment(CaseWriteRun $write, array $submitted): JSONResponse {
		$write->act        = PortalClientWriteEvent::ACT_AMENDMENT;
		$write->occurredAt = $this->recorder->now();
		$data = $this->amendmentData(write: $write, submitted: $submitted);
		try {
			$updated = $this->updateCase(write: $write, data: $data);
		} catch (\Throwable $e) {
			return $this->amendmentNotSaved(error: $e);
		}

		if ($updated === null) {
			return $this->caseNotYours();
		}

		$this->announceWrite(write: $write, fields: array_map(strval(...), array_keys($submitted)));

		return new JSONResponse(['case' => (new CitizenCaseProjection(logger: $this->logger))->visible(context: $write->context, case: $updated)]);
	}//end applyAmendment()

	/**
	 * What an amendment writes: the accepted fields, then the record carrying
	 * both answers of each.
	 *
	 * @param CaseWriteRun         $write     The amendment being written.
	 * @param array<string, mixed> $submitted The accepted fields.
	 *
	 * @return array<string, mixed>
	 */
	private function amendmentData(CaseWriteRun $write, array $submitted): array {
		$changes = [];
		foreach ($submitted as $field => $value) {
			$changes[$field] = ['from' => ($write->context['case'][$field] ?? null), 'to' => $value];
		}

		$data = $submitted;
		$data[$this->writeConfigField(write: $write, key: 'recordField')] = $this->writeRecord(write: $write, changes: $changes);

		return $data;
	}//end amendmentData()

	/**
	 * The refusal for an amendment the store did not save, logged.
	 *
	 * @param \Throwable $error Why it was not saved.
	 *
	 * @return JSONResponse
	 */
	private function amendmentNotSaved(\Throwable $error): JSONResponse {
		$this->logger->error('Citizen amendment failed: ' . $error->getMessage(), ['exception' => $error]);
		return $this->refuse(
			message: $this->l10n->t('The change could not be saved. Please try again.'),
			slug: 'amendment-not-saved',
			status: Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end amendmentNotSaved()

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

	/**
	 * Stamp the entity and mandate this write is made under onto the subject.
	 *
	 * A request may NAME an entity; it can never grant itself one. The named
	 * mandate must be one the identity holds, and the named entity must be one
	 * that mandate reaches at this moment. Anything else leaves the subject
	 * exactly as it was, so the write is recorded as their own.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	private function actingAs(array $subject): array {
		$entity = (string)$this->request->getParam('actingFor', '');
		if ($entity === '') {
			return $subject;
		}

		$described = $this->mandateReaching(subject: $subject, entity: $entity);
		if ($described === null) {
			return $subject;
		}

		$subject['actingForEntity'] = $entity;
		$subject['actingUnderMandate'] = $described['id'];
		// The name the mandate holder and the represented person know it by,
		// so the case app can say "namens" in words (write-record-names-the-mandate).
		$subject['actingForLabel'] = (string)($described['label'] ?? '');

		return $subject;
	}//end actingAs()

	/**
	 * The described mandate the request names when the identity holds it and
	 * it reaches the named entity at this moment, else null.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string               $entity  The entity the request names.
	 *
	 * @return array<string, mixed>|null
	 */
	private function mandateReaching(array $subject, string $entity): ?array {
		$active = $this->heldMandate(subject: $subject);
		if ($active === null) {
			return null;
		}

		$described = $this->mandates->describe(mandate: $active);
		$scope     = $this->mandateScope(mandate: $active, described: $described);
		if ($scope['refused'] === true || in_array($entity, $scope['entities'], true) === false) {
			return null;
		}

		return $described;
	}//end mandateReaching()

	/**
	 * The active mandate the request names among those the identity holds,
	 * or null.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<string, mixed>|null
	 */
	private function heldMandate(array $subject): ?array {
		$held = $this->mandates->mandatesFor(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			holders: $this->mandateParties->holdersOf(subject: $subject)
		);
		return $this->mandates->activeMandate(mandates: $held, mandateId: (string)$this->request->getParam('mandate', ''));
	}//end heldMandate()

	/**
	 * The entities a mandate reaches: from the party it is held on behalf of,
	 * else its organisation, down the tree when its reach says so.
	 *
	 * @param array<string, mixed> $mandate   The active mandate.
	 * @param array<string, mixed> $described The mandate, described.
	 *
	 * @return array{entities: array<int, string>, refused: bool}
	 */
	private function mandateScope(array $mandate, array $described): array {
		$party = $described['onBehalfOf'];
		if ($party === '') {
			$party = $described['organisation'];
		}

		return $this->tree->entitiesFor(
			root: $party,
			reachesDown: ($this->mandates->reachOf(mandate: $mandate) === PortalMandateServiceInterface::REACH_TREE)
		);
	}//end mandateScope()
	/**
	 * Which contributed action admits a citizen write here.
	 *
	 * Built from this controller's own registry rather than injected, so the
	 * constructor is unchanged and no caller or test had to move when the
	 * lookup was split out.
	 *
	 * @return CitizenWriteActionFinder
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	private function writeActions(): CitizenWriteActionFinder {
		if ($this->writeActions === null) {
			$this->writeActions = new CitizenWriteActionFinder(registry: $this->registry);
		}

		return $this->writeActions;
	}//end writeActions()
}//end class
