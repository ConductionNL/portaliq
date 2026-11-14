<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Portaliq\Listener\SchemaWriteGuard;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A guard over the schema SchemaWriteGuard looks up, exposing the lookup.
 */
final class SchemaWriteGuardProbe {
	use SchemaWriteGuard;

	private const GUARDED_SCHEMA = 'portalNotice';

	private const GUARD_NAME = 'probe guard';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Whether the guard applies to this schema id.
	 *
	 * @param string $schema The schema id of the object being written.
	 *
	 * @return bool
	 */
	public function applies(string $schema): bool {
		return $this->guardsSchema(schema: $schema);
	}
}//end class

/**
 * The guard applies only to the schema id its slug resolves to, and fails
 * open with a warning when the lookup is unavailable.
 *
 * @covers \OCA\Portaliq\Listener\SchemaWriteGuard
 */
class SchemaWriteGuardLookupTest extends TestCase {

	/**
	 * A container whose schema mapper answers the given schema, or throws.
	 *
	 * @param object|null     $schema  The schema found, or null.
	 * @param \Throwable|null $failure Thrown by the lookup instead.
	 * @param array<int, array<string, string>> $asked Receives the lookups.
	 *
	 * @return ContainerInterface
	 */
	private function container(?object $schema, ?\Throwable $failure, array &$asked): ContainerInterface {
		$mapper = new class($schema, $failure, $asked) {
			/**
			 * Constructor.
			 *
			 * @param object|null     $schema  The schema found.
			 * @param \Throwable|null $failure Thrown instead.
			 * @param array           $asked   Receives the lookups.
			 */
			public function __construct(private ?object $schema, private ?\Throwable $failure, private array &$asked) {
			}

			/**
			 * The schema of this app with this slug.
			 *
			 * @param string $slug        The slug.
			 * @param string $application The app id.
			 *
			 * @return object|null
			 */
			public function findByApplicationAndSlug(string $slug, string $application): ?object {
				$this->asked[] = ['slug' => $slug, 'application' => $application];
				if ($this->failure !== null) {
					throw $this->failure;
				}

				return $this->schema;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Db\\SchemaMapper')->willReturn($mapper);

		return $container;
	}//end container()

	/**
	 * A schema with an id.
	 *
	 * @param int $id The id.
	 *
	 * @return object
	 */
	private static function schema(int $id): object {
		return new class($id) {
			/**
			 * Constructor.
			 *
			 * @param int $id The id.
			 */
			public function __construct(private int $id) {
			}

			/**
			 * The id.
			 *
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}
		};
	}//end schema()

	/**
	 * Only the id the guarded slug resolves to is guarded.
	 *
	 * @return void
	 */
	public function testOnlyTheResolvedSchemaIdIsGuarded(): void {
		$asked = [];
		$guard = new SchemaWriteGuardProbe($this->container(self::schema(42), null, $asked), $this->createMock(LoggerInterface::class));

		$this->assertTrue($guard->applies('42'));
		$this->assertFalse($guard->applies('43'));
		$this->assertFalse($guard->applies(''));
		$this->assertSame(['slug' => 'portalNotice', 'application' => 'portaliq'], $asked[0]);
	}//end testOnlyTheResolvedSchemaIdIsGuarded()

	/**
	 * A schema not installed guards nothing.
	 *
	 * @return void
	 */
	public function testAMissingSchemaGuardsNothing(): void {
		$asked = [];
		$guard = new SchemaWriteGuardProbe($this->container(null, null, $asked), $this->createMock(LoggerInterface::class));

		$this->assertFalse($guard->applies('42'));
	}//end testAMissingSchemaGuardsNothing()

	/**
	 * A failing lookup is logged under the guard's name and guards nothing.
	 *
	 * @return void
	 */
	public function testAFailingLookupIsLoggedAndGuardsNothing(): void {
		$asked = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Portaliq: probe guard could not read the schema',
			['reason' => 'mapper unavailable']
		);
		$guard = new SchemaWriteGuardProbe($this->container(null, new RuntimeException('mapper unavailable'), $asked), $logger);

		$this->assertFalse($guard->applies('42'));
	}//end testAFailingLookupIsLoggedAndGuardsNothing()
}//end class
