<?php

/**
 * Portaliq Register Context
 *
 * Points OpenRegister's shared ObjectService at one of Portaliq's schemas in a
 * way that cannot inherit another app's leftover context.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-cms-read-must-not-inherit-another-apps-openregister-context
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies Portaliq's register/schema context to OpenRegister's ObjectService.
 *
 * WHY THIS EXISTS, MEASURED RATHER THAN THEORISED.
 *
 * `ObjectService` is one instance shared by every app in a request, and
 * `setSchema('slug')` deliberately leaves the RAW slug pending so a later
 * `setRegister()` can re-resolve it inside the register the caller names. That
 * makes the two setters order-independent — for the chain that created the
 * ref. It is instance state, so the ref outlives that chain, and the FIRST
 * `setRegister()` that follows re-resolves someone else's slug inside a
 * register that has never heard of it, and THROWS.
 *
 * Observed on the development instance 2026-08-27: every public content read
 * failed with `Schema slug "application" is not carried by register
 * "portaliq"` — a slug this app does not have, does not use, and never asked
 * for. The portal served its shell and answered `not_found` for its own site,
 * menus, pages and glossary. OpenRegister's own comments record the same
 * failure hitting openconnector, pipelinq and the flow engine before us.
 *
 * The immunity is in the ORDER and in the TYPE:
 *
 *   1. `setSchema()` with a resolved ENTITY clears any pending ref and assigns
 *      directly — it resolves nothing, so a foreign register in the context
 *      cannot capture it.
 *   2. `setRegister()` then finds our register with no ref pending, so it
 *      re-resolves nothing either.
 *
 * Passing slugs in either order re-enters the resolution that carries the
 * hazard, which is why this class resolves the schema itself.
 *
 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-cms-read-must-not-inherit-another-apps-openregister-context
 */
class PortalRegisterContext {
	/**
	 * OpenRegister's schema mapper.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * The register every Portaliq schema lives in.
	 *
	 * @var string
	 */
	public const REGISTER = 'portaliq';

	/**
	 * Schema entities already resolved in this request, keyed by slug.
	 *
	 * @var array<string, object>
	 */
	private array $resolved = [];


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For the lazy OpenRegister lookup.
	 * @param LoggerInterface    $logger    Records a schema this app owns but cannot find.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * Point an ObjectService at one of this app's schemas.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $schemaSlug    The Portaliq schema slug.
	 *
	 * @return bool True when the context was applied.
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-a-cms-read-must-not-inherit-another-apps-openregister-context
	 */
	public function apply(object $objectService, string $schemaSlug): bool {
		$schema = $this->schema(slug: $schemaSlug);
		if ($schema === null) {
			return false;
		}

		// ENTITY FIRST, REGISTER SECOND. Both halves matter; see the class
		// docblock. Reversing them, or passing the slug instead of the entity,
		// re-enters the resolution that another app's leftover ref hijacks.
		$objectService->setSchema(schema: $schema);
		$objectService->setRegister(register: self::REGISTER);

		return true;
	}//end apply()


	/**
	 * Resolve one of this app's schemas to an entity.
	 *
	 * Scoped to the schema PORTALIQ OWNS: `page`, `menu` and `portal` are about
	 * as generic as slugs get, and this instance runs ~20 apps in one
	 * OpenRegister. A global slug lookup would hand back whichever app's row
	 * came first, and the read would succeed against someone else's content.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return object|null The schema entity, or null when it is not there.
	 */
	private function schema(string $slug): ?object {
		if (isset($this->resolved[$slug]) === true) {
			return $this->resolved[$slug];
		}

		try {
			$mapper = $this->container->get(self::SCHEMA_MAPPER);
			$schema = $mapper->findByApplicationAndSlug(slug: $slug, application: self::REGISTER);
		} catch (Throwable $e) {
			$this->logger->error(
				'Portaliq: schema lookup failed',
				['slug' => $slug, 'reason' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($schema) === false) {
			$this->logger->error(
				'Portaliq: this app owns no schema with that slug',
				['slug' => $slug]
			);
			return null;
		}

		$this->resolved[$slug] = $schema;

		return $schema;
	}//end schema()
}//end class
