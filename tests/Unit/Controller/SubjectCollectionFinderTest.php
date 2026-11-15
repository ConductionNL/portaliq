<?php

/**
 * SubjectCollectionFinder: the collection a request names, looked up only
 * among the contributions the subject may see.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\SubjectCollectionFinder;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use PHPUnit\Framework\TestCase;

/**
 * The subject's collection finder.
 */
class SubjectCollectionFinderTest extends TestCase {
	/**
	 * A finder over a registry that answers the subject with two apps.
	 *
	 * @param array<string, mixed> $subject The subject the registry expects.
	 *
	 * @return SubjectCollectionFinder
	 */
	private function finder(array $subject): SubjectCollectionFinder {
		$registry = $this->createMock(originalClassName: PortalContributionRegistryInterface::class);
		$registry->expects($this->once())->method('aggregateFor')->with($subject)->willReturn(
			[
				'contributions' => [
					[
						'app'         => 'dossiq',
						'collections' => [['id' => 'cases', 'register' => 'zaken', 'schema' => 'zaak'], ['id' => 'closed', 'register' => 'zaken', 'schema' => 'zaak']],
					],
					['app' => 'pipelinq', 'collections' => [['id' => 'requests', 'register' => 'crm', 'schema' => 'request']]],
				],
			]
		);
		return new SubjectCollectionFinder(registry: $registry);
	}//end finder()

	/**
	 * A request naming a register, schema and collection finds that collection and its app.
	 *
	 * @return void
	 */
	public function testANamedCollectionIsFound(): void {
		$subject = ['subjectRef' => 'bsn-1'];
		$this->assertSame(
			expected: ['collection' => ['id' => 'closed', 'register' => 'zaken', 'schema' => 'zaak'], 'app' => 'dossiq'],
			actual: $this->finder(subject: $subject)->find(
				subject: $subject,
				requested: ['register' => 'zaken', 'schema' => 'zaak', 'collection' => 'closed']
			)
		);
	}//end testANamedCollectionIsFound()

	/**
	 * Without a collection id the first collection over that register and schema is found.
	 *
	 * @return void
	 */
	public function testWithoutAnIdTheFirstMatchIsFound(): void {
		$subject = ['subjectRef' => 'bsn-1'];
		$request = ['register' => 'crm', 'schema' => 'request', 'collection' => ''];
		$found   = $this->finder(subject: $subject)->find(subject: $subject, requested: $request);
		$this->assertSame(
			expected: ['collection' => ['id' => 'requests', 'register' => 'crm', 'schema' => 'request'], 'app' => 'pipelinq'],
			actual: $found
		);
	}//end testWithoutAnIdTheFirstMatchIsFound()

	/**
	 * A schema, register or collection the subject's contributions do not declare is not found.
	 *
	 * @return void
	 */
	public function testAnUndeclaredCollectionIsNotFound(): void {
		$subject   = ['subjectRef' => 'bsn-1'];
		$requested = [
			['register' => 'zaken', 'schema' => 'besluit', 'collection' => ''],
			['register' => 'crm', 'schema' => 'zaak', 'collection' => ''],
			['register' => 'zaken', 'schema' => 'zaak', 'collection' => 'requests'],
		];
		foreach ($requested as $request) {
			$this->assertNull(actual: $this->finder(subject: $subject)->find(subject: $subject, requested: $request));
		}
	}//end testAnUndeclaredCollectionIsNotFound()

	/**
	 * A registry without contributions finds nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyRegistryFindsNothing(): void {
		$registry = $this->createMock(originalClassName: PortalContributionRegistryInterface::class);
		$registry->method('aggregateFor')->willReturn([]);
		$finder = new SubjectCollectionFinder(registry: $registry);
		$this->assertNull(actual: $finder->find(subject: [], requested: ['register' => 'zaken', 'schema' => 'zaak', 'collection' => '']));
	}//end testAnEmptyRegistryFindsNothing()
}//end class
