<?php

/**
 * Portaliq Landing Submission Drain
 *
 * Submits the landing-page answers portaliq stored as `landingPageSubmission`
 * objects into the destination their form now names (decision 179), through
 * the same DestinationIntake a live submit uses. The stored object's id goes
 * along as `externalReference` and as the Idempotency-Key, so a rerun never
 * creates a second object. A stored object is deleted only after its
 * destination object exists. One whose form names no destination, or whose
 * answers the destination refuses, is reported and kept.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;

/**
 * Drains `landingPageSubmission` objects into their form's destination.
 *
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
 */
class LandingSubmissionDrain {
	/**
	 * Where portaliq kept the submissions and the forms.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema being drained.
	 */
	private const SCHEMA = 'landingPageSubmission';

	/**
	 * The values a submission carried that are not the visitor's answers.
	 */
	private const SERVER_KEYS = [
		'formId', 'pageId', 'pageRoute', 'portal', 'sourceApp', 'externalReference', 'campaign',
		'submittedAt', 'nonce', 'values', 'id', 'uuid', '@self', 'organisation',
	];

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads the stored submissions and their forms.
	 * @param PortalObjectWriter $writer Deletes a delivered submission.
	 * @param DestinationIntake  $intake Submits into the destination, as a live submit does.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly DestinationIntake $intake,
	) {
	}//end __construct()

	/**
	 * Drain up to `$limit` stored submissions.
	 *
	 * @param int $limit How many to read.
	 *
	 * @return array{delivered: int, refused: int, reasons: array<string, string>, waiting: int}
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
	 */
	public function drain(int $limit = 200): array {
		$forms  = $this->formsById();
		$result = ['delivered' => 0, 'refused' => 0, 'reasons' => [], 'waiting' => 0];
		foreach ($this->rows(limit: $limit) as $row) {
			$outcome = $this->deliver(row: $row, form: ($forms[(string)($row['formId'] ?? '')] ?? null));
			if ($outcome === null) {
				$result['delivered']++;
				continue;
			}

			$result['refused']++;
			$result['reasons'][self::idOf(row: $row)] = $outcome;
		}

		$result['waiting'] = $this->waiting(limit: $limit);
		return $result;
	}//end drain()

	/**
	 * How many stored submissions wait.
	 *
	 * @param int $limit How many to read.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
	 */
	public function waiting(int $limit = 200): int {
		return count($this->rows(limit: $limit));
	}//end waiting()

	/**
	 * Submit one stored submission; null when it was delivered and deleted, else why not.
	 *
	 * @param array<string, mixed>      $row  The stored submission.
	 * @param array<string, mixed>|null $form Its form, or null when the form is gone.
	 *
	 * @return string|null
	 */
	private function deliver(array $row, ?array $form): ?string {
		$destination = (array)($form['destination'] ?? []);
		if ((string)($destination['register'] ?? '') === '' || (string)($destination['schema'] ?? '') === '') {
			return 'its form names no destination';
		}

		$id      = self::idOf(row: $row);
		$answers = self::answersOf(row: $row);
		$fields  = array_map(static fn (string $name): array => ['name' => $name], array_keys($answers));
		$server  = array_intersect_key($row, array_flip(['formId', 'pageId', 'pageRoute', 'portal', 'sourceApp', 'campaign', 'submittedAt']));
		$server['externalReference'] = $id;

		$submitted = $this->intake->submit(
			binding: ['destination' => $destination, 'portal' => (string)($row['portal'] ?? ''), 'route' => (string)($row['pageRoute'] ?? '')],
			render: ['fields' => $fields],
			answers: $answers,
			server: $server,
			idempotencyKey: $id
		);
		if ($submitted['ok'] === false) {
			return 'the destination refused it (' . $submitted['status'] . '): ' . implode('; ', array_values($submitted['errors']));
		}

		$deleted = $this->writer->deleteObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'formId',
			subjectRef: (string)($row['formId'] ?? ''),
			organisation: '',
			id: $id
		);
		if ($deleted === false) {
			return 'it was delivered but could not be deleted';
		}

		return null;
	}//end deliver()

	/**
	 * The visitor's answers: the stored `values`, else every field that is not a server value.
	 *
	 * @param array<string, mixed> $row The stored submission.
	 *
	 * @return array<string, mixed>
	 */
	private static function answersOf(array $row): array {
		$values = $row['values'] ?? null;
		if (is_array($values) === true && $values !== []) {
			return $values;
		}

		return array_diff_key($row, array_flip(self::SERVER_KEYS));
	}//end answersOf()

	/**
	 * Every form by id, active or not: a stored submission may belong to a form taken down since.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function formsById(): array {
		$forms = [];
		foreach ($this->reader->readCollection(register: self::REGISTER, schema: 'form', scopeField: '', subjectRef: '', limit: 200) as $form) {
			if (is_array($form) === true && self::idOf(row: $form) !== '') {
				$forms[self::idOf(row: $form)] = $form;
			}
		}

		return $forms;
	}//end formsById()

	/**
	 * The stored submissions.
	 *
	 * @param int $limit How many to read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(int $limit): array {
		$rows = $this->reader->readCollection(register: self::REGISTER, schema: self::SCHEMA, scopeField: '', subjectRef: '', limit: $limit);

		return array_values(array_filter($rows, 'is_array'));
	}//end rows()

	/**
	 * A row's id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		return (string)($row['uuid'] ?? $row['id'] ?? $self['uuid'] ?? $self['id'] ?? '');
	}//end idOf()
}//end class
