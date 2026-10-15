<?php

/**
 * Portaliq Portal Party Tree Resolver
 *
 * Which entities a mandate reaches, read from the party relations OpenRegister
 * already holds, at the moment of the request. The relations are OpenRegister's
 * generic tree read over the party schema named in the app config (register,
 * schema and identity field; decision 250).
 *
 * Portaliq keeps no hierarchy of its own on purpose. A group changes by being
 * recorded as changed, not by somebody remembering to revoke eleven grants: a
 * subsidiary sold this morning is out of the answer this afternoon, and one
 * acquired this morning is in it, with nothing written either way.
 *
 * The walk is bounded in both directions it can run away in: a maximum depth
 * and a page size per level. Past the bound the resolution REFUSES. It does
 * not return the part it managed, because a partial list presented as a
 * complete one is the failure mode worth preventing here: somebody would read
 * it as "the group has no more cases".
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Walks the party tree, bounded, without keeping any of it.
 *
 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
 */
class PortalPartyTreeResolver {
	/**
	 * The default maximum depth of the walk.
	 */
	public const DEFAULT_MAX_DEPTH = 4;

	/**
	 * The default number of entities a tree may hold before it is refused.
	 */
	public const DEFAULT_PAGE_SIZE = 100;

	/**
	 * App config key naming the register the parties live in.
	 */
	public const CONFIG_REGISTER = 'party_tree_register';

	/**
	 * App config key naming the party schema.
	 */
	public const CONFIG_SCHEMA = 'party_tree_schema';

	/**
	 * App config key naming the property that carries a party's identity.
	 */
	public const CONFIG_IDENTITY = 'party_tree_identity';

	/**
	 * Where KvK companies live as objects in this fleet: dossiq's company
	 * register set, kept current from KvK by integriq.
	 */
	public const DEFAULT_REGISTER = 'dossiq';

	/**
	 * The default party schema.
	 */
	public const DEFAULT_SCHEMA = 'kvkCompany';

	/**
	 * The default identity property.
	 */
	public const DEFAULT_IDENTITY = 'kvkNumber';

	/**
	 * Constructor.
	 *
	 * @param PartyTreeSource $source    Reads OpenRegister's tree.
	 * @param IAppConfig      $appConfig Names the party schema.
	 */
	public function __construct(
		private readonly PartyTreeSource $source,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The entities a mandate on `$root` reaches, root included.
	 *
	 * @param string $root The entity the mandate names.
	 * @param bool $reachesDown Whether the mandate reaches below it.
	 * @param array<string, mixed> $bounds `maxDepth` and `pageSize` overrides.
	 *
	 * @return array{entities: array<int, string>, refused: bool, bound: array<string, int>}
	 *         `refused` true means the group is past the bound and NOTHING may
	 *         be listed from it; `entities` is then empty.
	 *
	 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	public function entitiesFor(string $root, bool $reachesDown, array $bounds = []): array {
		if ($reachesDown === false) {
			// A flat mandate stays flat: no relation is even read.
			$bound = $this->bound(bounds: $bounds);
			$entities = [];
			if ($root !== '') {
				$entities = [$root];
			}

			return ['entities' => $entities, 'refused' => false, 'bound' => $bound];
		}

		$tree = $this->treeFor(root: $root, bounds: $bounds);

		return [
			'entities' => array_column($tree['nodes'], 'identifier'),
			'refused' => $tree['refused'],
			'bound' => $tree['bound'],
		];
	}//end entitiesFor()

	/**
	 * The organisations a tree mandate on `$root` reaches, by level.
	 *
	 * Level 1 is the organisation the mandate names, level 2 the ones directly
	 * below it, and so on. With no tree recorded the answer is the root alone.
	 *
	 * @param string $root The entity the mandate names.
	 * @param array<string, mixed> $bounds `maxDepth` and `pageSize` overrides.
	 *
	 * @return array{nodes: array<int, array{identifier: string, name: string|null, level: int}>,
	 *     refused: bool, bound: array<string, int>}
	 *     `refused` true means the group is past a bound or could not be read,
	 *     and `nodes` is then empty.
	 *
	 * @spec openspec/specs/portal-visibility-and-the-party-tree/spec.md
	 */
	public function treeFor(string $root, array $bounds = []): array {
		$bound = $this->bound(bounds: $bounds);
		if ($root === '') {
			return ['nodes' => [], 'refused' => false, 'bound' => $bound];
		}

		$identity = $this->config(key: self::CONFIG_IDENTITY, default: self::DEFAULT_IDENTITY);
		$tree = $this->source->below(
			register: $this->config(key: self::CONFIG_REGISTER, default: self::DEFAULT_REGISTER),
			schema: $this->config(key: self::CONFIG_SCHEMA, default: self::DEFAULT_SCHEMA),
			identityField: $identity,
			identity: $root,
			maxDepth: $bound['maxDepth']
		);

		if ($tree === null) {
			return ['nodes' => [['identifier' => $root, 'name' => null, 'level' => 1]], 'refused' => false, 'bound' => $bound];
		}

		// A partial tree presented as the whole one would read as "the group
		// has no more cases", so a truncated answer is a refusal.
		$nodes = (array)($tree['nodes'] ?? []);
		if (($tree['truncated'] ?? false) === true || count($nodes) > $bound['pageSize']) {
			return ['nodes' => [], 'refused' => true, 'bound' => $bound];
		}

		$out = [];
		$seen = [];
		foreach ($nodes as $node) {
			$identifier = $this->identifierOf(node: (array)$node, identity: $identity, root: $root);
			if ($identifier === '' || isset($seen[$identifier]) === true) {
				continue;
			}

			$seen[$identifier] = true;
			$name = null;
			if (isset($node['name']) === true) {
				$name = (string)$node['name'];
			}

			$out[] = [
				'identifier' => $identifier,
				'name' => $name,
				'level' => ((int)($node['level'] ?? 0) + 1),
			];
		}

		return ['nodes' => $out, 'refused' => false, 'bound' => $bound];
	}//end treeFor()

	/**
	 * A held mandate with the organisations it reaches, when it reaches down.
	 *
	 * `reachesTo` lists `{identifier, name, level}`, level 1 being the
	 * organisation the mandate names; `reachRefused` is true when the group
	 * is past a bound or could not be read, and the list is then empty.
	 *
	 * @param array<string, mixed> $mandate The mandate as the held listing shows it.
	 *
	 * @return array<string, mixed> The mandate.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-the-holder-must-see-how-far-a-mandate-reaches-req-smr-008
	 */
	public function withReach(array $mandate): array {
		$mandate['reachesTo'] = [];
		$mandate['reachRefused'] = false;
		if (($mandate['reach'] ?? '') !== PortalMandateService::REACH_TREE) {
			return $mandate;
		}

		$root = (string)preg_replace('/^(kvk|subject):/', '', (string)($mandate['onBehalfOf'] ?? ''));
		$tree = $this->treeFor(root: $root);
		$mandate['reachesTo'] = $tree['nodes'];
		$mandate['reachRefused'] = $tree['refused'];

		return $mandate;
	}//end withReach()

	/**
	 * The identity of one node: the root keeps the mandate's own value.
	 *
	 * @param array<string, mixed> $node     The node.
	 * @param string               $identity The identity property.
	 * @param string               $root     The root's identity.
	 *
	 * @return string The identity.
	 */
	private function identifierOf(array $node, string $identity, string $root): string {
		if ((int)($node['level'] ?? 0) === 0) {
			return $root;
		}

		return trim((string)($node['properties'][$identity] ?? ''));
	}//end identifierOf()

	/**
	 * The bounds, defaulted.
	 *
	 * @param array<string, mixed> $bounds The overrides.
	 *
	 * @return array{maxDepth: int, pageSize: int}
	 */
	private function bound(array $bounds): array {
		return [
			'maxDepth' => max(1, (int)($bounds['maxDepth'] ?? self::DEFAULT_MAX_DEPTH)),
			'pageSize' => max(1, (int)($bounds['pageSize'] ?? self::DEFAULT_PAGE_SIZE)),
		];
	}//end bound()

	/**
	 * One config value, defaulted when empty.
	 *
	 * @param string $key     The key.
	 * @param string $default The default.
	 *
	 * @return string The value.
	 */
	private function config(string $key, string $default): string {
		$value = trim($this->appConfig->getValueString(Application::APP_ID, $key, ''));
		if ($value === '') {
			return $default;
		}

		return $value;
	}//end config()
}//end class
