<?php

/**
 * Portaliq Portal Admin Grants
 *
 * Writes a portal's `adminGroups` into the write rules of the content it
 * owns, as conditional rules that only match that portal's rows.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Tenancy
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
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

use OCA\Portaliq\Kernel\AppIdentity;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Grants a portal's administrators the content of that portal, and nothing else.
 *
 * Pages, menus, notices and media are written through OpenRegister's object
 * API, so the grant that counts is the schema rule OpenRegister evaluates. A
 * portal's groups enter each rule as `{group, match: {portal: <slug>}}`, which
 * holds only for that portal's rows; on the portal schema itself the match is
 * on `slug`. Rules of any other shape (the instance-wide editor groups, the
 * public rule on published pages) are left as they are.
 *
 * Only an action the schema lists is touched. OpenRegister reads an empty
 * list as "nobody", so a grant there opens that portal's rows to its groups
 * only; a missing list reads as open to every signed-in user, so a grant
 * there would close it to everyone else, and it is left alone. The PortalAdminWriteGuardListener refuses what a rule cannot express
 * (moving a row to another portal, changing `adminGroups`).
 *
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
 */
class PortalAdminGrants {

	/**
	 * The content schemas a portal owns, keyed by slug, with the field naming the portal.
	 *
	 * @var array<string, string>
	 */
	public const SCHEMAS = [
		'page'         => 'portal',
		'menu'         => 'portal',
		'portalNotice' => 'portal',
		'media'        => 'portal',
		'portal'       => 'slug',
	];

	/**
	 * The actions a portal's administrators are granted on its content.
	 *
	 * @var array<int, string>
	 */
	public const ACTIONS = ['read', 'create', 'update', 'delete'];

	/**
	 * OpenRegister's schema mapper, resolved lazily from the container.
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container For the lazy OpenRegister lookup.
	 * @param LoggerInterface    $logger    Records a schema write that did not happen.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write one portal's groups into the rules of every schema it owns.
	 *
	 * @param string             $slug         The portal slug; '' removes the grants of `$previousSlug` only.
	 * @param array<int, string> $groups       The portal's `adminGroups`.
	 * @param string             $previousSlug The slug before this write, whose grants are removed.
	 *
	 * @return bool True when every schema that exists was updated.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public function apply(string $slug, array $groups, string $previousSlug = ''): bool {
		try {
			$mapper = $this->container->get(self::SCHEMA_MAPPER);
			foreach (self::SCHEMAS as $schemaSlug => $field) {
				$schema = $mapper->findByApplicationAndSlug(slug: $schemaSlug, application: AppIdentity::APP_ID);
				if ($schema === null) {
					continue;
				}

				$authorization = $schema->getAuthorization();
				if (is_array($authorization) === false) {
					$authorization = [];
				}

				$updated = self::withPortal(
					authorization: $authorization,
					field: $field,
					slug: $slug,
					groups: $groups,
					previousSlug: $previousSlug
				);
				if ($updated !== $authorization) {
					$schema->setAuthorization($updated);
					$mapper->update($schema);
				}
			}
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: the portal administrators were not written into the schema rules', ['reason' => $e->getMessage()]);
			return false;
		}//end try

		return true;
	}//end apply()

	/**
	 * The authorization block with this portal's rules replaced.
	 *
	 * @param array<string, mixed> $authorization The schema's block.
	 * @param string               $field         The row field naming the portal.
	 * @param string               $slug          The portal slug; '' adds nothing.
	 * @param array<int, string>   $groups        The portal's groups.
	 * @param string               $previousSlug  A former slug whose rules are removed too.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public static function withPortal(array $authorization, string $field, string $slug, array $groups, string $previousSlug = ''): array {
		$drop   = array_values(array_filter([$slug, $previousSlug], static fn (string $s): bool => $s !== ''));
		$groups = PortalAdminScope::adminGroupsOf(portal: ['adminGroups' => $groups]);

		foreach (self::ACTIONS as $action) {
			if (isset($authorization[$action]) === false || is_array($authorization[$action]) === false) {
				continue;
			}

			$kept = [];
			foreach ($authorization[$action] as $rule) {
				if (in_array(self::portalOf(rule: $rule, field: $field), $drop, true) === false) {
					$kept[] = $rule;
				}
			}

			if ($slug !== '') {
				foreach ($groups as $gid) {
					$kept[] = ['group' => $gid, 'match' => [$field => $slug]];
				}
			}

			$authorization[$action] = $kept;
		}

		return $authorization;
	}//end withPortal()

	/**
	 * The rules among these that this class wrote, for any portal.
	 *
	 * @param mixed  $rules One action's rules.
	 * @param string $field The row field naming the portal.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public static function grantsIn(mixed $rules, string $field = 'portal'): array {
		if (is_array($rules) === false) {
			return [];
		}

		return array_values(array_filter($rules, static fn (mixed $rule): bool => self::portalOf(rule: $rule, field: $field) !== null));
	}//end grantsIn()

	/**
	 * The portal a rule written by this class is for, or null for any other rule.
	 *
	 * @param mixed  $rule  One rule.
	 * @param string $field The row field naming the portal.
	 *
	 * @return string|null
	 */
	private static function portalOf(mixed $rule, string $field): ?string {
		if (is_array($rule) === false || array_keys($rule) !== ['group', 'match'] || is_array($rule['match']) === false) {
			return null;
		}

		if (array_keys($rule['match']) !== [$field] || is_string($rule['match'][$field]) === false) {
			return null;
		}

		return $rule['match'][$field];
	}//end portalOf()
}//end class
