<?php

/**
 * Portaliq Page Editor Service
 *
 * Decides who may edit portal pages, and makes that decision REAL by writing
 * it into the `page` schema's authorization block rather than keeping it in
 * the interface that offers the editing.
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
 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves and enforces who may edit portal pages.
 *
 * THE SETTING IS NOT THE ENFORCEMENT, AND THAT DISTINCTION IS THE WHOLE POINT
 * OF THIS CLASS. The designer writes pages through OpenRegister's object API
 * (ADR-022) — no Portaliq controller sits in front of that write to check
 * anything. So a configured group only means something once it is named in the
 * schema's `create`/`update`/`delete` rules, where OpenRegister evaluates it on
 * every write regardless of which client made it.
 *
 * {@see mayEdit()} exists for the INTERFACE — whether to offer a floating
 * editing control, whether to open the designer. It is deliberately the same
 * predicate, but it is not the guard: hiding a button is a courtesy, and the
 * refusal that matters happens in OpenRegister.
 *
 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
 */
class PageEditorService {
	/**
	 * OpenRegister's schema mapper, resolved lazily from the container.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * The app config key holding the editor groups, as a JSON array of gids.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'editor_groups';

	/**
	 * The slug of the schema whose write rules this service governs.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'page';

	/**
	 * The actions the editor groups are granted on that schema.
	 *
	 * `read` is deliberately absent: it carries the public rule that serves
	 * published pages anonymously, and rewriting it here would take a portal
	 * offline for every visitor to configure who may edit it.
	 *
	 * @var array<string>
	 */
	private const WRITE_ACTIONS = [
		'create',
		'update',
		'delete',
	];


	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig    Stores the configured groups.
	 * @param IGroupManager      $groupManager Resolves membership and admin rights.
	 * @param IUserSession       $userSession  The calling user, when there is one.
	 * @param ContainerInterface $container    For the lazy OpenRegister lookup.
	 * @param LoggerInterface    $logger       Records a schema write that did not happen.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()


	/**
	 * The configured editor groups.
	 *
	 * @return array<string> The group ids, possibly empty.
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function getEditorGroups(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			// A value written by hand, or by an older format: treat a bare
			// string as one group rather than silently configuring none.
			return $this->normalise(groups: [$raw]);
		}

		return $this->normalise(groups: $decoded);
	}//end getEditorGroups()


	/**
	 * Configure the editor groups and push them into the schema.
	 *
	 * The persisted value and the schema are written together on purpose. A
	 * setting that saved but did not reach the schema would show the operator
	 * their chosen groups on every subsequent visit while OpenRegister kept
	 * refusing (or, worse, kept allowing) exactly as before.
	 *
	 * @param array<mixed> $groups The requested group ids.
	 *
	 * @return array<string> The normalised, stored group ids.
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function setEditorGroups(array $groups): array {
		$normalised = $this->normalise(groups: $groups);
		$this->appConfig->setValueString(
			Application::APP_ID,
			self::CONFIG_KEY,
			json_encode(array_values($normalised))
		);

		$this->applyToSchema(groups: $normalised);

		return $normalised;
	}//end setEditorGroups()


	/**
	 * Whether a user may edit portal pages.
	 *
	 * Fail-closed in both directions that matter: no session is never an
	 * editor, and a configured-groups list that is empty leaves only the
	 * administrators — it does not fall back to "everyone signed in", which is
	 * what an empty authorization list would mean if this predicate were
	 * written as "not restricted".
	 *
	 * @param IUser|null $user The user to test, or null for the current session.
	 *
	 * @return bool True when the user may edit.
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
	 */
	public function mayEdit(?IUser $user = null): bool {
		$subject = ($user ?? $this->userSession->getUser());
		if ($subject === null) {
			return false;
		}

		if ($this->groupManager->isAdmin($subject->getUID()) === true) {
			return true;
		}

		foreach ($this->getEditorGroups() as $gid) {
			if ($this->groupManager->isInGroup($subject->getUID(), $gid) === true) {
				return true;
			}
		}

		return false;
	}//end mayEdit()


	/**
	 * Every group on the instance, for the admin settings picker.
	 *
	 * @return array<array{id: string, label: string}> The groups, id-sorted.
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function availableGroups(): array {
		$groups = [];
		foreach ($this->groupManager->search('') as $group) {
			$groups[] = [
				'id'    => $group->getGID(),
				'label' => $group->getDisplayName(),
			];
		}

		usort($groups, static fn (array $a, array $b) => strcasecmp($a['id'], $b['id']));

		return $groups;
	}//end availableGroups()


	/**
	 * Write the configured groups into the `page` schema's write rules.
	 *
	 * Scoped to the schema PORTALIQ OWNS. `page` is about as generic as a slug
	 * gets and this instance runs ~20 apps in one OpenRegister; a global
	 * slug lookup would happily hand back another app's schema and this method
	 * would then rewrite the authorization of content it has no business
	 * touching.
	 *
	 * An empty group list writes empty rules, which OpenRegister reads as
	 * "grant to nobody" — administrators still bypass RBAC, so the schema
	 * falls back to admin-only rather than to the default-open behaviour a
	 * schema with no write rules at all still has.
	 *
	 * @param array<string> $groups The normalised group ids.
	 *
	 * @return bool True when the schema was updated.
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-who-may-edit-pages-must-be-configurable-and-enforced-at-the-write
	 */
	public function applyToSchema(array $groups): bool {
		$mapper = $this->schemaMapper();
		if ($mapper === null) {
			$this->logger->warning('Portaliq: editor groups saved but OpenRegister is unavailable, page schema not updated');
			return false;
		}

		try {
			$schema = $mapper->findByApplicationAndSlug(slug: self::PAGE_SLUG, application: Application::APP_ID);
			if ($schema === null) {
				$this->logger->warning('Portaliq: editor groups saved but the page schema is not imported yet');
				return false;
			}

			$authorization = ($schema->getAuthorization() ?? []);
			if (is_array($authorization) === false) {
				$authorization = [];
			}

			foreach (self::WRITE_ACTIONS as $action) {
				$authorization[$action] = array_values($groups);
			}

			$schema->setAuthorization($authorization);
			$mapper->update($schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Portaliq: failed to write the page schema authorization',
				['reason' => $e->getMessage()]
			);
			return false;
		}//end try

		return true;
	}//end applyToSchema()


	/**
	 * Normalise a requested group list to unique, non-empty group ids.
	 *
	 * Accepts both the bare ids the API stores and the `{id, label}` objects
	 * the settings picker hands back, because a picker that round-trips its own
	 * option objects is the normal case and silently storing `[null, null]`
	 * would configure nobody while looking configured.
	 *
	 * @param array<mixed> $groups The requested groups.
	 *
	 * @return array<string> The normalised ids.
	 */
	private function normalise(array $groups): array {
		$ids = [];
		foreach ($groups as $entry) {
			$gid = '';
			if (is_string($entry) === true) {
				$gid = trim($entry);
			} else if (is_array($entry) === true && isset($entry['id']) === true && is_string($entry['id']) === true) {
				$gid = trim($entry['id']);
			}

			if ($gid !== '' && in_array($gid, $ids, true) === false) {
				$ids[] = $gid;
			}
		}

		return $ids;
	}//end normalise()


	/**
	 * Resolve OpenRegister's SchemaMapper, or null when unavailable.
	 *
	 * @return object|null The mapper, or null.
	 */
	private function schemaMapper(): ?object {
		try {
			$service = $this->container->get(self::SCHEMA_MAPPER);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end schemaMapper()
}//end class
