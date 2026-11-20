<?php

/**
 * An anonymous create is stored with the system identity as owner, even when
 * the browser holds a Nextcloud session; a create for a subject is not.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
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
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-contributed-action-must-post-where-its-type-is-honoured-req-ptb-012
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\UnownedSaveOptions;
use OCA\Portaliq\Tests\Doubles\TenantHarness;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for PortalObjectWriter's unowned anonymous write.
 */
class PortalObjectWriterUnownedTest extends TestCase {
	use TenantHarness;

	/**
	 * The object service double; it records the options of every save.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Build the recording object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = new class {
			/**
			 * The options of each save, in order.
			 *
			 * @var array<int, ObjectSaveOptions>
			 */
			public array $options = [];

			/**
			 * Record one save.
			 *
			 * @param array<string, mixed> $object        The object.
			 * @param ObjectSaveOptions    $options       The options.
			 * @param bool                 $_rbac         Unused.
			 * @param bool                 $_multitenancy Unused.
			 *
			 * @return array<string, mixed>
			 *
			 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
			 */
			public function saveObject(
				array $object,
				ObjectSaveOptions $options = new ObjectSaveOptions(),
				bool $_rbac = true,
				bool $_multitenancy = true
			): array {
				$this->options[] = $options;
				return $object + ['id' => 'new-1'];
			}//end saveObject()
		};
	}//end setUp()

	/**
	 * An anonymous create asks OpenRegister for the system identity as owner.
	 *
	 * @return void
	 */
	public function testAnAnonymousCreateIsStoredUnowned(): void {
		$saved = $this->writer()->createAnonymousObject('portaliq', 'exampleDocument', ['title' => 'Lamp kapot']);

		$this->assertSame(expected: 'Lamp kapot', actual: $saved['title']);
		$this->assertCount(expectedCount: 1, haystack: $this->objectService->options);
		$options = $this->objectService->options[0];
		$this->assertTrue(condition: $options->actor->unowned);
		$this->assertNull(actual: $options->actor->owner);
		$this->assertSame(expected: 'portaliq', actual: $options->target->register);
		$this->assertSame(expected: 'exampleDocument', actual: $options->target->schema);
	}//end testAnAnonymousCreateIsStoredUnowned()

	/**
	 * A create for a subject is attributed as before: it does not opt out.
	 *
	 * @return void
	 */
	public function testACreateForASubjectIsNotUnowned(): void {
		$this->writer()->createObject('portaliq', 'portalMessage', 'subjectRef', 's1', '', ['title' => 'X']);

		$this->assertCount(expectedCount: 1, haystack: $this->objectService->options);
		$this->assertFalse(condition: $this->objectService->options[0]->actor->unowned);
	}//end testACreateForASubjectIsNotUnowned()

	/**
	 * The options carry the target and the unowned actor on an OpenRegister
	 * that offers them.
	 *
	 * @return void
	 */
	public function testTheUnownedOptionsNameTheTarget(): void {
		$options = (new UnownedSaveOptions())->forTarget(register: 'r', schema: 's');

		$this->assertSame(expected: 'r', actual: $options->target->register);
		$this->assertSame(expected: 's', actual: $options->target->schema);
		$this->assertTrue(condition: $options->actor->unowned);
	}//end testTheUnownedOptionsNameTheTarget()

	/**
	 * The writer under test.
	 *
	 * @return PortalObjectWriter
	 */
	private function writer(): PortalObjectWriter {
		return new PortalObjectWriter(container: $this->tenantContainer(objectService: $this->objectService), logger: new NullLogger());
	}//end writer()
}//end class
