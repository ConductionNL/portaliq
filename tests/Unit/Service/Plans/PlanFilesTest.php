<?php

/**
 * Portaliq Plan Files Test
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Plans
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-participants-share-the-goal-actions-note-and-file-versions-req-spl-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Plans;

use OCA\Portaliq\Contribution\CitizenDocumentUpload;
use OCA\Portaliq\Service\Plans\PlanFiles;
use OCA\Portaliq\Service\Plans\PortalPlanService;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCP\AppFramework\Http\StreamResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A participant adds a file or a new version of one, and opens only files
 * the plan lists.
 *
 * @covers \OCA\Portaliq\Service\Plans\PlanFiles
 * @uses   \OCA\Portaliq\Contribution\CitizenDocumentUpload
 * @uses   \OCA\Portaliq\Service\UploadedFile
 * @uses   \OCA\Portaliq\Service\Plans\PlanRules
 */
class PlanFilesTest extends TestCase {

	private PortalPlanService&MockObject $plans;

	private PortalFileReader&MockObject $reader;

	private PortalFileWriter&MockObject $writer;

	/**
	 * Temporary upload files to remove.
	 *
	 * @var array<int, string>
	 */
	private array $temp = [];

	protected function tearDown(): void {
		foreach ($this->temp as $path) {
			@unlink($path);
		}
	}//end tearDown()

	/**
	 * The files service over a plan the viewer takes part in, or none.
	 *
	 * @param array<string, mixed>|null $plan The plan, or null for a stranger.
	 * @param bool $isOwner Whether the viewer owns it.
	 *
	 * @return PlanFiles
	 */
	private function files(?array $plan, bool $isOwner = false): PlanFiles {
		$this->plans = $this->createMock(PortalPlanService::class);
		$this->plans->method('membership')->willReturn(($plan === null) ? null : ['plan' => $plan, 'isOwner' => $isOwner]);
		$this->reader = $this->createMock(PortalFileReader::class);
		$this->writer = $this->createMock(PortalFileWriter::class);

		return new PlanFiles($this->plans, $this->reader, $this->writer, new CitizenDocumentUpload());
	}//end files()

	/**
	 * An upload entry as IRequest::getUploadedFile() gives it.
	 *
	 * @param string $name The file name.
	 *
	 * @return array<string, mixed>
	 */
	private function upload(string $name): array {
		$path = tempnam(sys_get_temp_dir(), 'plan');
		file_put_contents($path, 'bytes of ' . $name);
		$this->temp[] = $path;

		return ['name' => $name, 'tmp_name' => $path, 'error' => 0];
	}//end upload()

	/**
	 * A running plan with one file, version 1.
	 *
	 * @param array<string, mixed> $extra Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function plan(array $extra = []): array {
		return $extra + [
			'id' => 'p1',
			'owner' => 'sanne',
			'status' => 'running',
			'files' => [['id' => 'f1', 'name' => 'begroting.pdf', 'series' => 'f1', 'version' => 1, 'previousVersion' => '', 'addedBy' => 'sanne', 'addedAt' => '2026-09-01T10:00:00+02:00']],
		];
	}//end plan()

	public function testANewFileStartsItsOwnSeriesUnderAUniqueName(): void {
		$files = $this->files(self::plan(), isOwner: false);
		$this->reader->method('listFiles')->with('portaliq', 'portalPlan', 'p1')->willReturn([['name' => 'begroting.pdf']]);
		$this->writer->expects($this->once())->method('attachFile')
			->with('portaliq', 'portalPlan', 'p1', 'begroting-2.pdf', 'bytes of begroting.pdf')->willReturn(['id' => 'f2']);
		$saved = null;
		$this->plans->expects($this->once())->method('saveFiles')->willReturnCallback(
			static function (array $subject, array $plan, bool $isOwner, array $list) use (&$saved): string {
				$saved = [$isOwner, $list];
				return PortalPlanService::OK;
			}
		);

		$status = $files->add(['subjectRef' => 'mark'], 'p1', $this->upload('begroting.pdf'), '', '2026-09-02T09:00:00+02:00');

		$this->assertSame(PortalPlanService::OK, $status);
		$this->assertFalse($saved[0]);
		$this->assertSame(
			['series' => 'f2', 'version' => 1, 'previousVersion' => '', 'id' => 'f2', 'name' => 'begroting.pdf', 'addedBy' => 'mark', 'addedAt' => '2026-09-02T09:00:00+02:00'],
			$saved[1][1]
		);
	}

	public function testANewVersionContinuesTheSeriesOfTheOneItReplaces(): void {
		$files = $this->files(self::plan(), isOwner: true);
		$this->reader->method('listFiles')->willReturn([]);
		$this->writer->method('attachFile')->willReturn(['id' => 'f2']);
		$saved = [];
		$this->plans->method('saveFiles')->willReturnCallback(
			static function (array $subject, array $plan, bool $isOwner, array $list) use (&$saved): string {
				$saved = $list;
				return PortalPlanService::OK;
			}
		);

		$this->assertSame(PortalPlanService::OK, $files->add(['subjectRef' => 'sanne'], 'p1', $this->upload('begroting-v2.pdf'), 'f1'));
		$this->assertSame('f1', $saved[1]['series']);
		$this->assertSame(2, $saved[1]['version']);
		$this->assertSame('f1', $saved[1]['previousVersion']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $saved[1]['addedAt']);
	}

	public function testAStrangerADonePlanAnUnknownOrSupersededVersionAndAMissingUploadAreRefused(): void {
		$this->assertSame(PortalPlanService::NOT_FOUND, $this->files(null)->add(['subjectRef' => 'eve'], 'p1', $this->upload('x.pdf')));
		$this->assertSame(PortalPlanService::FORBIDDEN, $this->files(self::plan(['status' => 'done']))->add(['subjectRef' => 'mark'], 'p1', $this->upload('x.pdf')));

		$files = $this->files(self::plan());
		$this->writer->expects($this->never())->method('attachFile');
		$this->assertSame(PortalPlanService::INVALID, $files->add(['subjectRef' => 'mark'], 'p1', $this->upload('x.pdf'), 'f-unknown'));
		$this->assertSame(PortalPlanService::INVALID, $files->add(['subjectRef' => 'mark'], 'p1', null));

		$superseded = self::plan();
		$superseded['files'][] = ['id' => 'f2', 'name' => 'begroting.pdf', 'series' => 'f1', 'version' => 2, 'previousVersion' => 'f1'];
		$files = $this->files($superseded);
		$this->writer->expects($this->never())->method('attachFile');
		$this->assertSame(PortalPlanService::INVALID, $files->add(['subjectRef' => 'mark'], 'p1', $this->upload('x.pdf'), 'f1'));
	}

	public function testAnAttachmentWithoutAnIdFailsAndSavesNothing(): void {
		$files = $this->files(self::plan());
		$this->reader->method('listFiles')->willReturn([]);
		$this->writer->method('attachFile')->willReturn(null);
		$this->plans->expects($this->never())->method('saveFiles');

		$this->assertSame(PortalPlanService::FAILED, $files->add(['subjectRef' => 'mark'], 'p1', $this->upload('x.pdf')));
	}

	public function testOnlyAListedFileOpens(): void {
		$files = $this->files(self::plan());
		$stream = new StreamResponse('php://memory');
		$this->reader->expects($this->once())->method('streamFile')->with('portaliq', 'portalPlan', 'p1', 'f1')->willReturn($stream);

		$this->assertSame($stream, $files->open(['subjectRef' => 'mark'], 'p1', 'f1'));
		$this->assertNull($files->open(['subjectRef' => 'mark'], 'p1', 'f-not-listed'));
		$this->assertNull($files->open(['subjectRef' => 'mark'], 'p1', ''));
		$this->assertNull($this->files(null)->open(['subjectRef' => 'eve'], 'p1', 'f1'));
	}
}
