<?php

/**
 * Portaliq Plan Files
 *
 * The files of a shared plan: adding one, adding a new version of one, and
 * opening one. A new version never replaces the file before it.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Plans
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Plans;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Portaliq\Contribution\CitizenDocumentUpload;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCP\AppFramework\Http\StreamResponse;

/**
 * A plan file is an OpenRegister file on the plan object. The plan's `files`
 * list names each one with its version: a new version is a new file pointing
 * at the one before it through `previousVersion`, in the same `series`. Only a
 * file the plan lists can be opened, and only by a participant.
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-participants-share-the-goal-actions-note-and-file-versions-req-spl-003
 */
class PlanFiles {

	private const SCHEMA = 'portalPlan';

	/**
	 * Constructor.
	 *
	 * @param PortalPlanService $plans Finds the plan for a participant and saves its file list.
	 * @param PortalFileReader $reader Lists and streams the plan object's files.
	 * @param PortalFileWriter $writer Attaches a file to the plan object.
	 * @param CitizenDocumentUpload $upload Reads the upload and keeps stored names unique.
	 * @param PlanRules $rules Reads the plan's file list.
	 */
	public function __construct(
		private readonly PortalPlanService $plans,
		private readonly PortalFileReader $reader,
		private readonly PortalFileWriter $writer,
		private readonly CitizenDocumentUpload $upload,
		private readonly PlanRules $rules = new PlanRules(),
	) {
	}//end __construct()

	/**
	 * Add a file to a plan, or a new version of a file it holds.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param array<string, mixed>|null $uploaded The entry from IRequest::getUploadedFile().
	 * @param string $previousVersion The file this one is a new version of, or ''.
	 * @param string $now The moment, ISO 8601; now when empty.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN (a done plan), INVALID (no file, or not the newest version) or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-participants-share-the-goal-actions-note-and-file-versions-req-spl-003
	 */
	public function add(array $subject, string $id, ?array $uploaded, string $previousVersion='', string $now=''): string {
		$membership = $this->plans->membership(subject: $subject, id: $id);
		if ($membership === null) {
			return PortalPlanService::NOT_FOUND;
		}

		$plan = $membership['plan'];
		if (($plan['status'] ?? 'running') === 'done') {
			return PortalPlanService::FORBIDDEN;
		}

		$files = $this->rules->filesOf(plan: $plan);
		$entry = self::nextEntry(files: $files, previousVersion: $previousVersion);
		$file  = $this->upload->read($uploaded);
		if ($entry === null || $file === null) {
			return PortalPlanService::INVALID;
		}

		$attached = $this->writer->attachFile(
			register: PortalPlanService::REGISTER,
			schema: self::SCHEMA,
			id: $id,
			fileName: $this->upload->uniqueName(
				existing: $this->reader->listFiles(register: PortalPlanService::REGISTER, schema: self::SCHEMA, id: $id),
				fileName: $file['name']
			),
			content: $file['content']
		);
		$fileId = (string)($attached['id'] ?? '');
		if ($fileId === '') {
			return PortalPlanService::FAILED;
		}

		if ($now === '') {
			$now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		}

		$entry['id']      = $fileId;
		$entry['name']    = $file['name'];
		$entry['addedBy'] = (string)($subject['subjectRef'] ?? '');
		$entry['addedAt'] = $now;
		if ($entry['series'] === '') {
			$entry['series'] = $fileId;
		}

		$files[] = $entry;

		return $this->plans->saveFiles(subject: $subject, plan: $plan, isOwner: $membership['isOwner'], files: $files);
	}//end add()

	/**
	 * Open one file of a plan.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param string $fileId The file.
	 *
	 * @return StreamResponse|null Null for a stranger, a file the plan does not list, or a file that cannot be read.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-participants-share-the-goal-actions-note-and-file-versions-req-spl-003
	 */
	public function open(array $subject, string $id, string $fileId): ?StreamResponse {
		$membership = $this->plans->membership(subject: $subject, id: $id);
		if ($membership === null || $fileId === '') {
			return null;
		}

		$listed = array_column($this->rules->filesOf(plan: $membership['plan']), 'id');
		if (in_array($fileId, $listed, true) === false) {
			return null;
		}

		return $this->reader->streamFile(register: PortalPlanService::REGISTER, schema: self::SCHEMA, id: $id, fileId: $fileId);
	}//end open()

	/**
	 * The series, version and predecessor of the next file, or null when
	 * `previousVersion` is not the newest version of a file the plan holds.
	 *
	 * @param array<int, array<string, mixed>> $files The plan's files.
	 * @param string $previousVersion The file the new one follows, or ''.
	 *
	 * @return array{series: string, version: int, previousVersion: string}|null
	 */
	private static function nextEntry(array $files, string $previousVersion): ?array {
		if ($previousVersion === '') {
			return ['series' => '', 'version' => 1, 'previousVersion' => ''];
		}

		$byId = array_column($files, null, 'id');
		if (isset($byId[$previousVersion]) === false) {
			return null;
		}

		$earlier = $byId[$previousVersion];
		foreach ($files as $file) {
			if ($file['previousVersion'] === $previousVersion) {
				return null;
			}
		}

		return ['series' => $earlier['series'], 'version' => ($earlier['version'] + 1), 'previousVersion' => $previousVersion];
	}//end nextEntry()
}//end class
