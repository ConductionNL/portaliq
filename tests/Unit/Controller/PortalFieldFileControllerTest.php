<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\PortalFieldFileController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalFileFieldPolicy;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The scoped upload into a declared file field (assignment-portal-file-upload).
 * The real policy runs on a fixed clock; the reader, writers and registry are
 * doubles, so every test can assert what was, and what was never, reached.
 *
 * @spec openspec/changes/assignment-portal-file-upload/specs/portal-contribution-contract/spec.md#requirement-a-subject-must-be-able-to-upload-into-a-declared-file-field-of-an-object-they-own
 */
class PortalFieldFileControllerTest extends TestCase {
	/**
	 * The fixed "now": 2026-09-27T12:00:00Z.
	 */
	private const NOW = 1790510400;

	/**
	 * The pupil.
	 */
	private const SUBJECT = [
		'subjectRef' => 'learner-1',
		'audience' => 'student',
		'organisation' => 'school-1',
		'trust' => 'low',
		'jti' => 'jti-1',
	];

	/**
	 * Temp files made by upload fixtures, removed after each test.
	 *
	 * @var array<int, string>
	 */
	private array $tmpFiles = [];

	/**
	 * Remove the temp files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->tmpFiles as $tmp) {
			if (is_file($tmp) === true) {
				unlink($tmp);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The hand-in action learniq declares.
	 *
	 * @param string $type The action type.
	 *
	 * @return array<string, mixed>
	 */
	private function action(string $type = 'create'): array {
		return [
			'id' => 'createSubmission',
			'type' => $type,
			'register' => 'learniq',
			'schema' => 'submission',
			'scopeField' => 'learnerRef',
			'scopeClaim' => 'learnerRef',
			'fields' => ['assignmentId', 'attachmentRefs'],
			'fieldConfigs' => [
				'attachmentRefs' => ['type' => 'file', 'multiple' => true, 'accept' => ['.pdf', '.txt'], 'maxSizeMb' => 1, 'size' => 'medium'],
			],
		];
	}//end action()

	/**
	 * An owned submission created some seconds ago.
	 *
	 * @param int $ageSeconds How long ago it was created.
	 * @param array<int, string> $refs The references it holds.
	 *
	 * @return array<string, mixed>
	 */
	private function submission(int $ageSeconds = 60, array $refs = ['4702']): array {
		return [
			'id' => 'submission-1',
			'learnerRef' => 'learner-1',
			'attachmentRefs' => $refs,
			'@self' => ['id' => 'submission-1', 'created' => gmdate('c', self::NOW - $ageSeconds)],
		];
	}//end submission()

	/**
	 * The happy path: the file is attached to the owned submission and its id
	 * is appended to `attachmentRefs` through the ownership-checking writer.
	 *
	 * @return void
	 */
	public function testUploadAttachesAndAppendsTheReference(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->once())->method('readObject')
			->with('learniq', 'submission', 'learnerRef', 'learner-1', 'submission-1', 'school-1')
			->willReturn($this->submission());
		// A create action proves ownership with the subjectRef create stamps,
		// so the claim is never resolved.
		$reader->expects($this->never())->method('resolveScopeValue');

		$fileWriter = $this->createMock(PortalFileWriter::class);
		$fileWriter->expects($this->once())->method('attachFile')
			->with('learniq', 'submission', 'submission-1', 'essay.txt', "Mijn werkstuk.\n")
			->willReturn(['id' => 4711, 'name' => 'essay.txt', 'size' => 15]);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('updateObject')
			->with('learniq', 'submission', 'learnerRef', 'learner-1', 'school-1', 'submission-1', ['attachmentRefs' => ['4702', '4711']])
			->willReturn(['id' => 'submission-1']);

		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->once())->method('record');

		$response = $this->controller(
			actions: [$this->action()],
			reader: $reader,
			writer: $writer,
			fileWriter: $fileWriter,
			auditor: $auditor,
			upload: ['name' => 'essay.txt', 'content' => "Mijn werkstuk.\n"]
		)->upload('learniq', 'submission', 'submission-1', 'attachmentRefs');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['file' => ['id' => '4711', 'name' => 'essay.txt', 'size' => 15], 'field' => 'attachmentRefs', 'value' => ['4702', '4711']],
			$response->getData()
		);
	}//end testUploadAttachesAndAppendsTheReference()

	/**
	 * An update action resolves its claim to prove ownership and has no window.
	 *
	 * @return void
	 */
	public function testAnUpdateActionResolvesItsClaimAndHasNoWindow(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->once())->method('resolveScopeValue')->willReturn('profile-9');
		$reader->expects($this->once())->method('readObject')
			->with('learniq', 'submission', 'learnerRef', 'profile-9', 'submission-1', 'school-1')
			->willReturn($this->submission(ageSeconds: 86400));

		$fileWriter = $this->createMock(PortalFileWriter::class);
		$fileWriter->expects($this->once())->method('attachFile')->willReturn(['id' => 5000, 'name' => 'a.txt', 'size' => 3]);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('updateObject')
			->with('learniq', 'submission', 'learnerRef', 'profile-9', 'school-1', 'submission-1', ['attachmentRefs' => ['4702', '5000']])
			->willReturn(['id' => 'submission-1']);

		$response = $this->controller(
			actions: [$this->action(type: 'update')],
			reader: $reader,
			writer: $writer,
			fileWriter: $fileWriter
		)->upload('learniq', 'submission', 'submission-1', 'attachmentRefs');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testAnUpdateActionResolvesItsClaimAndHasNoWindow()

	/**
	 * No session is 401, before anything else.
	 *
	 * @return void
	 */
	public function testNoSessionIs401(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->never())->method('readObject');

		$response = $this->controller(actions: [$this->action()], reader: $reader, subject: null)
			->upload('learniq', 'submission', 'submission-1', 'attachmentRefs');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testNoSessionIs401()

	/**
	 * A field that is not a declared file field, an unnamed action, a named
	 * action that is not the subject's and a trust below minTrust are all 403
	 * before the object is read.
	 *
	 * @return void
	 */
	public function testUndeclaredFieldIs403BeforeAnyRead(): void {
		$lowTrust = $this->action();
		$lowTrust['minTrust'] = 'substantial';
		$cases = [
			'undeclared field' => [[$this->action()], 'createSubmission', 'assignmentId'],
			'unwhitelisted field' => [[$this->action()], 'createSubmission', 'learnerRef'],
			'no action named' => [[$this->action()], '', 'attachmentRefs'],
			'foreign action' => [[$this->action()], 'somebodyElsesAction', 'attachmentRefs'],
			'trust below minTrust' => [[$lowTrust], 'createSubmission', 'attachmentRefs'],
		];

		foreach ($cases as $label => [$actions, $actionId, $field]) {
			$reader = $this->createMock(PortalObjectReader::class);
			$reader->expects($this->never())->method('readObject');
			$fileWriter = $this->createMock(PortalFileWriter::class);
			$fileWriter->expects($this->never())->method('attachFile');

			$response = $this->controller(actions: $actions, reader: $reader, fileWriter: $fileWriter, actionId: $actionId)
				->upload('learniq', 'submission', 'submission-1', $field);

			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $label);
			$this->assertSame(['error' => 'forbidden'], $response->getData(), $label);
		}
	}//end testUndeclaredFieldIs403BeforeAnyRead()

	/**
	 * A foreign or absent object is one 404, and nothing is attached.
	 *
	 * @return void
	 */
	public function testForeignObjectIs404BeforeAnyAttach(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturn(null);
		$fileWriter = $this->createMock(PortalFileWriter::class);
		$fileWriter->expects($this->never())->method('attachFile');

		$response = $this->controller(actions: [$this->action()], reader: $reader, fileWriter: $fileWriter)
			->upload('learniq', 'submission', 'someone-elses-submission', 'attachmentRefs');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());
	}//end testForeignObjectIs404BeforeAnyAttach()

	/**
	 * An update action whose claim does not resolve is the same 404.
	 *
	 * @return void
	 */
	public function testAnUnresolvedClaimIs404(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturn(null);
		$reader->expects($this->never())->method('readObject');

		$response = $this->controller(actions: [$this->action(type: 'update')], reader: $reader)
			->upload('learniq', 'submission', 'submission-1', 'attachmentRefs');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testAnUnresolvedClaimIs404()

	/**
	 * A create action cannot add a file to a submission made an hour ago.
	 *
	 * @return void
	 */
	public function testCreateWindowClosedRefusesBeforeAnyAttach(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturn($this->submission(ageSeconds: 3600));
		$fileWriter = $this->createMock(PortalFileWriter::class);
		$fileWriter->expects($this->never())->method('attachFile');

		$response = $this->controller(actions: [$this->action()], reader: $reader, fileWriter: $fileWriter)
			->upload('learniq', 'submission', 'submission-1', 'attachmentRefs');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'upload_window_closed'], $response->getData());
	}//end testCreateWindowClosedRefusesBeforeAnyAttach()

	/**
	 * No file part, a refused type, an oversized file and a full field all
	 * refuse before the attach.
	 *
	 * @return void
	 */
	public function testFileChecksRefuseBeforeAnyAttach(): void {
		$full = array_map('strval', range(1, PortalFileFieldPolicy::MAX_FILES_PER_FIELD));
		$cases = [
			'no file' => [null, $this->submission(), Http::STATUS_BAD_REQUEST, 'no_file'],
			'wrong type' => [['name' => 'run.exe', 'content' => 'MZ'], $this->submission(), Http::STATUS_UNSUPPORTED_MEDIA_TYPE, 'file_type_refused'],
			'too large' => [['name' => 'big.txt', 'content' => str_repeat('a', (1024 * 1024) + 1)], $this->submission(), Http::STATUS_REQUEST_ENTITY_TOO_LARGE, 'file_too_large'],
			'field full' => [['name' => 'one-more.txt', 'content' => 'one more note'], $this->submission(refs: $full), Http::STATUS_CONFLICT, 'too_many_files'],
		];

		foreach ($cases as $label => [$upload, $row, $status, $error]) {
			$reader = $this->createMock(PortalObjectReader::class);
			$reader->method('readObject')->willReturn($row);
			$fileWriter = $this->createMock(PortalFileWriter::class);
			$fileWriter->expects($this->never())->method('attachFile');

			$response = $this->controller(actions: [$this->action()], reader: $reader, fileWriter: $fileWriter, upload: $upload)
				->upload('learniq', 'submission', 'submission-1', 'attachmentRefs');

			$this->assertSame($status, $response->getStatus(), $label);
			$this->assertSame(['error' => $error], $response->getData(), $label);
		}
	}//end testFileChecksRefuseBeforeAnyAttach()

	/**
	 * A failed attach is 502 and nothing is written.
	 *
	 * @return void
	 */
	public function testAFailedAttachWritesNothing(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturn($this->submission());
		$fileWriter = $this->createMock(PortalFileWriter::class);
		$fileWriter->method('attachFile')->willReturn(null);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('updateObject');

		$response = $this->controller(actions: [$this->action()], reader: $reader, writer: $writer, fileWriter: $fileWriter)
			->upload('learniq', 'submission', 'submission-1', 'attachmentRefs');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['error' => 'upload_failed'], $response->getData());
	}//end testAFailedAttachWritesNothing()

	/**
	 * A refused or throwing reference write is 502 and is not audited.
	 *
	 * @return void
	 */
	public function testAFailedReferenceWriteIs502(): void {
		foreach (['refused' => null, 'thrown' => new RuntimeException('tenant org-2 row 9')] as $label => $outcome) {
			$reader = $this->createMock(PortalObjectReader::class);
			$reader->method('readObject')->willReturn($this->submission());
			$fileWriter = $this->createMock(PortalFileWriter::class);
			$fileWriter->method('attachFile')->willReturn(['id' => 4711]);
			$writer = $this->createMock(PortalObjectWriter::class);
			if ($outcome === null) {
				$writer->method('updateObject')->willReturn(null);
			}

			if ($outcome !== null) {
				$writer->method('updateObject')->willThrowException($outcome);
			}

			$auditor = $this->createMock(AuditTrailService::class);
			$auditor->expects($this->never())->method('record');

			$response = $this->controller(actions: [$this->action()], reader: $reader, writer: $writer, fileWriter: $fileWriter, auditor: $auditor)
				->upload('learniq', 'submission', 'submission-1', 'attachmentRefs');

			$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus(), $label);
			$this->assertSame(['error' => 'write_failed'], $response->getData(), $label);
		}
	}//end testAFailedReferenceWriteIs502()

	/**
	 * Build the controller.
	 *
	 * @param array<int, array<string, mixed>> $actions The subject's actions.
	 * @param PortalObjectReader|null $reader The reader.
	 * @param PortalObjectWriter|null $writer The writer.
	 * @param PortalFileWriter|null $fileWriter The file writer.
	 * @param AuditTrailService|null $auditor The auditor.
	 * @param array{name: string, content: string}|null $upload The uploaded file, null for none.
	 * @param array<string, mixed>|null $subject The subject, null for no session.
	 * @param string $actionId The `?action=` parameter.
	 *
	 * @return PortalFieldFileController
	 */
	private function controller(
		array $actions,
		?PortalObjectReader $reader = null,
		?PortalObjectWriter $writer = null,
		?PortalFileWriter $fileWriter = null,
		?AuditTrailService $auditor = null,
		?array $upload = ['name' => 'essay.txt', 'content' => "Mijn werkstuk.\n"],
		?array $subject = self::SUBJECT,
		string $actionId = 'createSubmission',
	): PortalFieldFileController {
		$uploaded = [];
		if ($upload !== null) {
			$tmp = tempnam(sys_get_temp_dir(), 'pq');
			file_put_contents($tmp, $upload['content']);
			$this->tmpFiles[] = $tmp;
			$uploaded = ['tmp_name' => $tmp, 'error' => 0, 'name' => $upload['name']];
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($key === 'action' ? $actionId : $default));
		$request->method('getUploadedFile')->willReturn($uploaded);

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'learniq', 'collections' => [], 'actions' => $actions]]]);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->method('readSchema')->willReturn(['properties' => ['attachmentRefs' => ['type' => 'array']]]);

		return new PortalFieldFileController(
			$request,
			$registry,
			$session,
			($reader ?? $this->createMock(PortalObjectReader::class)),
			($writer ?? $this->createMock(PortalObjectWriter::class)),
			($fileWriter ?? $this->createMock(PortalFileWriter::class)),
			new PortalFileFieldPolicy($time, $schemaReader),
			($auditor ?? $this->createMock(AuditTrailService::class)),
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()
}//end class
