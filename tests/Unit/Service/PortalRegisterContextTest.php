<?php

/**
 * PortalRegisterContextTest
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalRegisterContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The context this app applies to OpenRegister's shared object service.
 *
 * WHAT IS BEING PROTECTED, stated because the assertions look pedantic without
 * it: `setSchema('slug')` leaves the raw slug pending on a service shared by
 * every app in the request, and the next `setRegister()` — whoever's it is —
 * re-resolves that slug inside ITS register and throws. Measured on the
 * development instance: every public read of a portal failed with
 * `Schema slug "application" is not carried by register "portaliq"`, a slug
 * this app does not own and never asked for.
 *
 * Passing a resolved ENTITY is what breaks that chain, so the test asserts the
 * argument's type — the one property whose loss would restore the bug while
 * every functional assertion kept passing.
 */
class PortalRegisterContextTest extends TestCase {

	/**
	 * Calls recorded from the object-service double.
	 *
	 * @var array
	 */
	private array $calls = [];


	/**
	 * Reset the recording.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calls = [];
	}//end setUp()


	/**
	 * A context helper whose mapper resolves this app's schemas.
	 *
	 * @param bool $owns Whether the app owns the requested schema.
	 *
	 * @return PortalRegisterContext The helper.
	 */
	private function context(bool $owns = true): PortalRegisterContext {
		$mapper = new class($owns) {

			/**
			 * Whether the app owns the schema.
			 *
			 * @var bool
			 */
			private bool $owns;


			/**
			 * Constructor.
			 *
			 * @param bool $owns Whether the app owns the schema.
			 */
			public function __construct(bool $owns) {
				$this->owns = $owns;
			}


			/**
			 * Resolve an app-owned schema.
			 *
			 * @param string $slug        The slug.
			 * @param string $application The owning app.
			 *
			 * @return object|null The entity, or null.
			 */
			public function findByApplicationAndSlug(string $slug, string $application): ?object {
				if ($this->owns === false || $application !== 'portaliq') {
					return null;
				}

				return (object)['slug' => $slug];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		return new PortalRegisterContext($container, $this->createMock(LoggerInterface::class));
	}//end context()


	/**
	 * An object-service double that records how it was addressed.
	 *
	 * @return object The double.
	 */
	private function objectService(): object {
		$test = $this;

		return new class($test) {

			/**
			 * The test case, for recording.
			 *
			 * @var object
			 */
			private object $test;


			/**
			 * Constructor.
			 *
			 * @param object $test The test case.
			 */
			public function __construct(object $test) {
				$this->test = $test;
			}


			/**
			 * Record the schema context.
			 *
			 * @param mixed $schema The schema, entity or slug.
			 *
			 * @return void
			 */
			public function setSchema(mixed $schema): void {
				$this->test->record('setSchema', $schema);
			}


			/**
			 * Record the register context.
			 *
			 * @param mixed $register The register.
			 *
			 * @return void
			 */
			public function setRegister(mixed $register): void {
				$this->test->record('setRegister', $register);
			}
		};
	}//end objectService()


	/**
	 * Record a call from the double.
	 *
	 * @param string $method   The method.
	 * @param mixed  $argument The argument.
	 *
	 * @return void
	 */
	public function record(string $method, mixed $argument): void {
		$this->calls[] = [$method, $argument];
	}//end record()


	/**
	 * The schema is applied as a resolved entity, never as a slug.
	 *
	 * A slug here is the whole bug: it is remembered as a PENDING ref on a
	 * shared service, and the next caller's `setRegister()` re-resolves it
	 * inside a register that has never heard of it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-cms-read-must-not-inherit-another-apps-openregister-context
	 */
	public function testTheSchemaIsAppliedAsAnEntity(): void {
		$service = $this->objectService();

		$this->assertTrue($this->context()->apply($service, 'page'));
		$this->assertSame('setSchema', $this->calls[0][0]);
		$this->assertIsObject(
			$this->calls[0][1],
			'The schema must be applied as a resolved entity; a slug re-enters '
			. 'the pending-ref resolution that another app can capture.'
		);
	}//end testTheSchemaIsAppliedAsAnEntity()


	/**
	 * The schema is set BEFORE the register.
	 *
	 * Setting the schema first is what clears a foreign pending ref: the
	 * register call that follows then has nothing left over to re-resolve.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-cms-read-must-not-inherit-another-apps-openregister-context
	 */
	public function testTheSchemaIsSetBeforeTheRegister(): void {
		$this->context()->apply($this->objectService(), 'page');

		$this->assertSame(
			['setSchema', 'setRegister'],
			array_column($this->calls, 0)
		);
		$this->assertSame('portaliq', $this->calls[1][1]);
	}//end testTheSchemaIsSetBeforeTheRegister()


	/**
	 * A schema this app does not own is refused, and nothing is applied.
	 *
	 * `page`, `menu` and `portal` are about as generic as slugs get, and this
	 * fleet runs ~20 apps in one OpenRegister. Reading another app's rows would
	 * succeed silently and serve someone else's content.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-cms-read-must-not-inherit-another-apps-openregister-context
	 */
	public function testAForeignSchemaIsRefused(): void {
		$service = $this->objectService();

		$this->assertFalse($this->context(owns: false)->apply($service, 'page'));
		$this->assertSame([], $this->calls, 'Nothing may be applied when the schema is not this app\'s.');
	}//end testAForeignSchemaIsRefused()
}//end class
