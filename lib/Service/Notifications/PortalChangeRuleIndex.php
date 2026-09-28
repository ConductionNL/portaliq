<?php

/**
 * Which contributions want a resident told about a record, by register and schema.
 *
 * PortalRecordChangeListener sees every OpenRegister create and update on the
 * instance, so its first question has to be cheap: "does any app declare a
 * rule for this register and schema?". This index answers it from a lookup
 * built once per request out of every installed app's contribution, for every
 * audience it serves. Only a hit reads further.
 *
 * Two kinds of entry:
 * - `change`: a change rule (REQ-NAP-001/002). The listener compares the rule's
 *   field between the old and the new record.
 * - `inbox`: a `kind: inbox` collection of an app that declares
 *   `message.created` (REQ-NAP-004). A new record in it is a message to the
 *   resident at its scope field.
 *
 * Collections name their register and schema by slug, while an OpenRegister
 * object carries numeric ids. The index maps the object's ids to slugs through
 * OpenRegister's own id-to-slug maps, and matches either form.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\NotificationDispatchService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Looks up change rules and inbox collections by register and schema.
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 */
class PortalChangeRuleIndex {

	/**
	 * OpenRegister's register mapper, resolved by name so portaliq does not
	 * hard-depend on it.
	 *
	 * @var string
	 */
	private const REGISTER_MAPPER = 'OCA\\OpenRegister\\Db\\RegisterMapper';

	/**
	 * OpenRegister's schema mapper.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * The entries, built on first use.
	 *
	 * @var array<int, array<string, string>>|null
	 */
	private ?array $entries = null;

	/**
	 * Register id to slug, and schema id to slug.
	 *
	 * @var array{register: array<string, string>, schema: array<string, string>}|null
	 */
	private ?array $slugs = null;

	/**
	 * Wire the index.
	 *
	 * @param PortalContributionRegistry $registry  Every app's contribution.
	 * @param ContainerInterface         $container Resolves OpenRegister's mappers.
	 * @param LoggerInterface            $logger    The logger.
	 */
	public function __construct(
		private readonly PortalContributionRegistry $registry,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The change rules for a record of this register and schema.
	 *
	 * @param string $register The object's register id or slug.
	 * @param string $schema   The object's schema id or slug.
	 *
	 * @return array<int, array<string, string>> Entries with app, ruleKey, collection, label, scopeField, field and titleField.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
	 */
	public function changeRulesFor(string $register, string $schema): array {
		return $this->matching(kind: 'change', register: $register, schema: $schema);
	}//end changeRulesFor()

	/**
	 * The inbox collections a new record of this register and schema lands in.
	 *
	 * @param string $register The object's register id or slug.
	 * @param string $schema   The object's schema id or slug.
	 *
	 * @return array<int, array<string, string>> Entries with app, collection and scopeField.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-apps-message-triggers-an-e-mail-req-nap-004
	 */
	public function inboxesFor(string $register, string $schema): array {
		return $this->matching(kind: 'inbox', register: $register, schema: $schema);
	}//end inboxesFor()

	/**
	 * Whether this register and schema are portaliq's own inbox messages.
	 *
	 * @param string $register The object's register id or slug.
	 * @param string $schema   The object's schema id or slug.
	 *
	 * @return bool
	 */
	public function isPortalMessage(string $register, string $schema): bool {
		return in_array('portaliq', $this->names(map: 'register', value: $register), true)
			&& in_array('portalMessage', $this->names(map: 'schema', value: $schema), true);
	}//end isPortalMessage()

	/**
	 * The entries of one kind that match the register and schema.
	 *
	 * @param string $kind     `change` or `inbox`.
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function matching(string $kind, string $register, string $schema): array {
		$entries = $this->entries();
		if ($entries === [] || $register === '' || $schema === '') {
			return [];
		}

		$registers = $this->names(map: 'register', value: $register);
		$schemas = $this->names(map: 'schema', value: $schema);
		$out = [];
		foreach ($entries as $entry) {
			if ($entry['kind'] === $kind && in_array($entry['register'], $registers, true) === true && in_array($entry['schema'], $schemas, true) === true) {
				$out[] = $entry;
			}
		}

		return $out;
	}//end matching()

	/**
	 * The names a register or schema answers to: the value itself and, for an
	 * id, its slug.
	 *
	 * @param string $map   `register` or `schema`.
	 * @param string $value The id or slug.
	 *
	 * @return array<int, string>
	 */
	private function names(string $map, string $value): array {
		$names = [$value];
		$slug = ($this->slugs()[$map][$value] ?? null);
		if (is_string($slug) === true && $slug !== '') {
			$names[] = $slug;
		}

		return $names;
	}//end names()

	/**
	 * OpenRegister's id-to-slug maps, read once.
	 *
	 * @return array{register: array<string, string>, schema: array<string, string>}
	 */
	private function slugs(): array {
		if ($this->slugs !== null) {
			return $this->slugs;
		}

		$this->slugs = ['register' => [], 'schema' => []];
		foreach (['register' => self::REGISTER_MAPPER, 'schema' => self::SCHEMA_MAPPER] as $key => $class) {
			try {
				$mapper = $this->container->get($class);
				$map = $mapper->getIdToSlugMap();
				foreach ($map as $id => $slug) {
					$this->slugs[$key][(string)$id] = (string)$slug;
				}
			} catch (Throwable $e) {
				$this->logger->warning('Portaliq: could not read OpenRegister slugs for change rules', ['map' => $key, 'reason' => $e->getMessage()]);
			}
		}

		return $this->slugs;
	}//end slugs()

	/**
	 * Every entry, built once from every contribution.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function entries(): array {
		if ($this->entries !== null) {
			return $this->entries;
		}

		$entries = [];
		foreach ($this->registry->contributionsForEveryAudience() as $contribution) {
			foreach ($this->entriesOf(contribution: $contribution) as $entry) {
				// Two audiences served by the same collection give the same
				// entry; keep one, so a change is reported once.
				$entries[implode('|', $entry)] = $entry;
			}
		}

		$this->entries = array_values($entries);

		return $this->entries;
	}//end entries()

	/**
	 * The entries one contribution declares.
	 *
	 * @param array<string, mixed> $contribution The normalised contribution.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function entriesOf(array $contribution): array {
		$app = (string)($contribution['app'] ?? '');
		$notifications = ($contribution['notifications'] ?? null);
		if ($app === '' || is_array($notifications) === false) {
			return [];
		}

		$collections = [];
		foreach ((array)($contribution['collections'] ?? []) as $collection) {
			if (is_array($collection) === true && (string)($collection['id'] ?? '') !== '') {
				$collections[(string)$collection['id']] = $collection;
			}
		}

		$entries = [];
		foreach ($notifications as $rule) {
			if (is_array($rule) === true && isset($collections[(string)($rule['collection'] ?? '')]) === true) {
				$collection = $collections[(string)$rule['collection']];
				$entries[] = $this->entry(kind: 'change', app: $app, collection: $collection) + [
					'ruleKey' => (string)$rule['ruleKey'],
					'field' => (string)($rule['on']['field'] ?? ''),
					'titleField' => (string)($rule['titleField'] ?? ''),
				];
			}
		}

		if (in_array(NotificationDispatchService::RULE_MESSAGE_CREATED, $notifications, true) === true) {
			foreach ($collections as $collection) {
				if ((string)($collection['kind'] ?? '') === 'inbox') {
					$entries[] = $this->entry(kind: 'inbox', app: $app, collection: $collection);
				}
			}
		}

		return $entries;
	}//end entriesOf()

	/**
	 * The fields every entry carries.
	 *
	 * @param string               $kind       `change` or `inbox`.
	 * @param string               $app        The contributing app.
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, string>
	 */
	private function entry(string $kind, string $app, array $collection): array {
		return [
			'kind' => $kind,
			'app' => $app,
			'collection' => (string)$collection['id'],
			'label' => (string)($collection['label'] ?? $collection['id']),
			'register' => (string)($collection['register'] ?? ''),
			'schema' => (string)($collection['schema'] ?? ''),
			'scopeField' => (string)($collection['scopeField'] ?? 'subjectRef'),
		];
	}//end entry()
}//end class
