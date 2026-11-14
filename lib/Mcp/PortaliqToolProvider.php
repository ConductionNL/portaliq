<?php

/**
 * Portaliq MCP Tool Provider (hermiq-ai-tooling)
 *
 * The one governed hand-written IMcpToolProvider of portaliq. Reads stay
 * derived from the x-openregister-mcp dialect in
 * lib/Settings/portaliq_register.json; this provider carries the staff write
 * actions and the one non-CRUD read (traffic totals). Every tool drives a
 * production code path that exists without MCP, re-checks the same authority
 * its UI equivalent needs, declares its scope and reach for hermiq's
 * governance, and leaves a fact-only audit row.
 *
 * MCP is an internal Nextcloud surface: every tool refuses a call without a
 * signed-in Nextcloud user, so a portal bearer (which never signs a Nextcloud
 * user in) cannot invoke one (ADR-046).
 *
 * @category Mcp
 * @package  OCA\Portaliq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-staff-write-action-is-a-curated-tool-governed-by-scope-and-reach
 */

declare(strict_types=1);

namespace OCA\Portaliq\Mcp;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Controller\TrafficReportController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PageEditorService;
use OCA\Portaliq\Service\PortalRegionResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\Theme\PortalThemeChoice;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficReportNumbers;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Curated, governed MCP tools for portaliq staff actions.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-portaliq-exposes-no-hand-written-mcp-tool
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One dispatcher over the seven production paths it fronts.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Seven small handlers, each a guard and one call.
 */
class PortaliqToolProvider implements IMcpToolProvider {

	/**
	 * The tools that wait for a person before they run (human-approval-gate).
	 *
	 * @var list<string>
	 */
	public const APPROVAL_REQUIRED = [
		'portaliq.publishPortalPage',
		'portaliq.sendPortalMessage',
		'portaliq.revokeOrganisationSessions',
	];

	/**
	 * What updatePortalContent may change, per schema. A page only takes its
	 * draft, so nothing a visitor sees changes until publishPortalPage; the
	 * `portal` scope field is never writable.
	 *
	 * @var array<string, list<string>>
	 */
	public const CONTENT_FIELDS = [
		'page' => ['draftBody'],
		'menu' => ['title', 'position', 'items'],
		'glossaryTerm' => ['term', 'definition', 'synonyms', 'source', 'relations'],
	];

	/**
	 * The audit verb each tool scope is recorded under (AuditTrailService::VERBS).
	 * A read takes data out of portaliq, so it is recorded as a download.
	 *
	 * @var array<string, string>
	 */
	private const AUDIT_VERBS = [
		'read' => 'download',
		'create' => 'create',
		'update' => 'update',
		'delete' => 'admin-revoke',
	];

	/**
	 * Constructor.
	 *
	 * @param IUserSession          $userSession The signed-in Nextcloud user, the only identity a tool accepts.
	 * @param IGroupManager         $groups      Decides administrator authority, as Nextcloud's admin-only controllers do.
	 * @param PageEditorService     $editors     Decides who may edit portal content, as the page editor does.
	 * @param StaffObjectStore      $objects     Reads and writes objects under the caller's own RBAC.
	 * @param PortalRegionResolver  $regions     Keeps only known regions, in render order.
	 * @param PortalThemeChoice     $themes      The theme choice behind PortalThemeController::update.
	 * @param PortalSessionService  $sessions    The revocation behind SessionAdminController::revokeOrganisation.
	 * @param TrafficEventStore     $traffic     The daily traffic rows behind TrafficReportController::summary.
	 * @param TrafficReportNumbers  $numbers     Folds daily rows into the summary totals.
	 * @param AuditTrailService     $auditor     Records one fact per executed tool; never throws.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly PageEditorService $editors,
		private readonly StaffObjectStore $objects,
		private readonly PortalRegionResolver $regions,
		private readonly PortalThemeChoice $themes,
		private readonly PortalSessionService $sessions,
		private readonly TrafficEventStore $traffic,
		private readonly TrafficReportNumbers $numbers,
		private readonly AuditTrailService $auditor,
	) {
	}//end __construct()

	/**
	 * The app id every tool id is namespaced under.
	 *
	 * @return string "portaliq"
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-portaliq-exposes-no-hand-written-mcp-tool
	 */
	public function getAppId(): string {
		return Application::APP_ID;
	}//end getAppId()

	/**
	 * The seven curated tools, each with scope, reach, hints, approval and
	 * the production code path it drives.
	 *
	 * @return list<array<string, mixed>> Tool descriptors.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-high-impact-writes-require-a-human-approval-gate
	 */
	public function getTools(): array {
		$tools = [];
		foreach (self::catalogue() as $tool) {
			$tool['requiresApproval'] = in_array($tool['id'], self::APPROVAL_REQUIRED, true);
			$tools[] = $tool;
		}

		return $tools;
	}//end getTools()

	/**
	 * Run one tool as the signed-in Nextcloud user. Never throws: every
	 * refusal and failure is an error envelope.
	 *
	 * @param string               $toolId    The namespaced tool id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed> The result, or `['error' => ..., 'message' => ...]`.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-mcp-tools-are-unreachable-from-the-external-portal-edge
	 */
	public function invokeTool(string $toolId, array $arguments): array {
		$scope = $this->scopeOf(toolId: $toolId);
		if ($scope === null) {
			return self::error(code: 'unknown_tool', message: 'Unknown tool id ' . $toolId . '.');
		}

		$uid = (string)$this->userSession->getUser()?->getUID();
		if ($uid === '') {
			// The same answer whatever else came with the call: a portal
			// bearer, a forged bearer or nothing at all.
			return self::error(code: 'unauthenticated', message: 'A signed-in Nextcloud user is required.');
		}

		$outcome = $this->dispatch(toolId: $toolId, arguments: $arguments, uid: $uid);
		if (isset($outcome['error']) === true) {
			return $outcome;
		}

		$this->auditor->record(
			verb: self::AUDIT_VERBS[$scope],
			subjectRef: $uid,
			organisation: (string)($outcome['audit']['organisation'] ?? ''),
			register: Application::APP_ID,
			schema: (string)($outcome['audit']['schema'] ?? ''),
			id: (string)($outcome['audit']['id'] ?? ''),
			detail: ['tool' => $toolId]
		);

		return $outcome['result'];
	}//end invokeTool()

	/**
	 * Route a tool to its handler.
	 *
	 * @param string               $toolId    The tool id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 * @param string               $uid       The acting Nextcloud user.
	 *
	 * @return array<string, mixed> `['result' => ..., 'audit' => ...]` or an error envelope.
	 */
	private function dispatch(string $toolId, array $arguments, string $uid): array {
		return match ($toolId) {
			'portaliq.trafficSummary' => $this->trafficSummary(arguments: $arguments, uid: $uid),
			'portaliq.updatePortalContent' => $this->updatePortalContent(arguments: $arguments),
			'portaliq.updatePageRegions' => $this->updatePageRegions(arguments: $arguments),
			'portaliq.applyTheme' => $this->applyTheme(arguments: $arguments, uid: $uid),
			'portaliq.publishPortalPage' => $this->publishPortalPage(arguments: $arguments),
			'portaliq.sendPortalMessage' => $this->sendPortalMessage(arguments: $arguments, uid: $uid),
			default => $this->revokeOrganisationSessions(arguments: $arguments, uid: $uid),
		};
	}//end dispatch()

	/**
	 * A portal's traffic totals for a period, as the admin KPI cards show them.
	 *
	 * @param array<string, mixed> $arguments `portal`, optional `days`.
	 * @param string               $uid       The acting user.
	 *
	 * @return array<string, mixed> The outcome.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-staff-write-action-is-a-curated-tool-governed-by-scope-and-reach
	 */
	private function trafficSummary(array $arguments, string $uid): array {
		if ($this->groups->isAdmin($uid) === false) {
			return self::forbidden();
		}

		$portal = self::text(arguments: $arguments, key: 'portal');
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,127}$/', $portal) !== 1) {
			return self::error(code: 'invalid_argument', message: 'portal must be a portal slug.');
		}

		$days = self::text(arguments: $arguments, key: 'days');
		if ($days === '') {
			$days = '30';
		}
		if (in_array($days, TrafficReportController::SUMMARY_DAYS, true) === false) {
			$periods = implode(', ', TrafficReportController::SUMMARY_DAYS);
			return self::error(code: 'invalid_argument', message: 'days must be one of ' . $periods . '.');
		}

		$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
		$from = $today->modify('-' . ((int)$days - 1) . ' days')->format('Y-m-d');
		$to = $today->format('Y-m-d');
		$folded = $this->numbers->fold(records: $this->traffic->dailyBetween(portal: $portal, from: $from, to: $to));

		return [
			'result' => [
				'portal' => $portal,
				'from' => $from,
				'to' => $to,
				'days' => (int)$days,
				'pageViews' => $folded['pageViews'] ?? 0,
				'sessions' => $folded['sessions'] ?? 0,
				'visitors' => $folded['visitors'] ?? 0,
				'engagedSessions' => $folded['engagedSessions'] ?? 0,
			],
			'audit' => ['schema' => 'trafficSummary', 'id' => $portal],
		];
	}//end trafficSummary()

	/**
	 * Edit a page draft, a menu or a glossary term as a content editor.
	 *
	 * @param array<string, mixed> $arguments `schema`, `id`, `fields`.
	 *
	 * @return array<string, mixed> The outcome.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-staff-write-action-is-a-curated-tool-governed-by-scope-and-reach
	 */
	private function updatePortalContent(array $arguments): array {
		if ($this->editors->mayEdit() === false) {
			return self::forbidden();
		}

		$schema = self::text(arguments: $arguments, key: 'schema');
		$id = self::text(arguments: $arguments, key: 'id');
		$fields = ($arguments['fields'] ?? null);
		$valid = (isset(self::CONTENT_FIELDS[$schema]) === true && $id !== '' && is_array($fields) === true && $fields !== []);
		if ($valid === false) {
			return self::error(
				code: 'invalid_argument',
				message: 'schema (page, menu or glossaryTerm), id and a non-empty fields object are required.'
			);
		}

		$refused = array_values(array_diff(array_keys($fields), self::CONTENT_FIELDS[$schema]));
		if ($refused !== []) {
			return self::error(code: 'invalid_argument', message: 'These fields cannot be changed with this tool: ' . implode(', ', $refused) . '.');
		}

		$current = $this->objects->find(schema: $schema, id: $id);
		if ($current === null) {
			return self::notFound();
		}

		$saved = $this->objects->save(schema: $schema, object: array_merge(self::withoutEnvelope(row: $current), $fields), id: $id);
		if ($saved === null) {
			return self::saveFailed();
		}

		return [
			'result' => ['id' => $id, 'schema' => $schema, 'updated' => array_keys($fields)],
			'audit' => ['schema' => $schema, 'id' => $id],
		];
	}//end updatePortalContent()

	/**
	 * Set a portal's region composition: known regions only, in render order.
	 *
	 * @param array<string, mixed> $arguments `portal` (object id), `regions`.
	 *
	 * @return array<string, mixed> The outcome.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-staff-write-action-is-a-curated-tool-governed-by-scope-and-reach
	 */
	private function updatePageRegions(array $arguments): array {
		if ($this->editors->mayEdit() === false) {
			return self::forbidden();
		}

		$id = self::text(arguments: $arguments, key: 'portal');
		$regions = ($arguments['regions'] ?? null);
		if ($id === '' || is_array($regions) === false || array_is_list($regions) === true) {
			return self::error(code: 'invalid_argument', message: 'portal and a regions object (region name to widget list) are required.');
		}

		$current = $this->objects->find(schema: 'portal', id: $id);
		if ($current === null) {
			return self::notFound();
		}

		$ordered = $this->regions->ordered(regions: $regions);
		$saved = $this->objects->save(
			schema: 'portal',
			object: array_merge(self::withoutEnvelope(row: $current), ['regions' => $this->regions->forJson(regions: $ordered)]),
			id: $id
		);
		if ($saved === null) {
			return self::saveFailed();
		}

		return [
			'result' => ['id' => $id, 'regions' => array_keys($ordered)],
			'audit' => ['schema' => 'portal', 'id' => $id],
		];
	}//end updatePageRegions()

	/**
	 * Choose a portal's theme, refused on the same grounds as the admin screen.
	 *
	 * @param array<string, mixed> $arguments `portal` (slug), `theme`, optional `acceptFindings`.
	 * @param string               $uid       The acting user.
	 *
	 * @return array<string, mixed> The outcome.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-staff-write-action-is-a-curated-tool-governed-by-scope-and-reach
	 */
	private function applyTheme(array $arguments, string $uid): array {
		if ($this->groups->isAdmin($uid) === false) {
			return self::forbidden();
		}

		$slug = self::text(arguments: $arguments, key: 'portal');
		$theme = self::text(arguments: $arguments, key: 'theme');
		if ($slug === '' || $theme === '') {
			return self::error(code: 'invalid_argument', message: 'portal and theme are required.');
		}

		$portal = $this->themes->portalBySlug(slug: $slug);
		if ($portal === null) {
			return self::notFound();
		}

		$result = $this->themes->choose(portal: $portal, theme: $theme);
		if (($arguments['acceptFindings'] ?? false) === true) {
			$result = $this->themes->chooseConfirmingFindings(portal: $portal, theme: $theme);
		}

		if (isset($result['error']) === true) {
			return self::error(code: (string)$result['error'], message: 'The theme was not applied.');
		}

		return [
			'result' => ['portal' => $slug, 'theme' => $theme, 'applied' => true],
			'audit' => ['schema' => 'portal', 'id' => (string)($portal['id'] ?? $slug)],
		];
	}//end applyTheme()

	/**
	 * Publish a page: its draft becomes the live body and the page goes live.
	 *
	 * @param array<string, mixed> $arguments `id`.
	 *
	 * @return array<string, mixed> The outcome.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-high-impact-writes-require-a-human-approval-gate
	 */
	private function publishPortalPage(array $arguments): array {
		if ($this->editors->mayEdit() === false) {
			return self::forbidden();
		}

		$id = self::text(arguments: $arguments, key: 'id');
		if ($id === '') {
			return self::error(code: 'invalid_argument', message: 'id is required.');
		}

		$page = $this->objects->find(schema: 'page', id: $id);
		if ($page === null) {
			return self::notFound();
		}

		$payload = self::withoutEnvelope(row: $page);
		if (array_key_exists('draftBody', $payload) === true && $payload['draftBody'] !== null) {
			$payload['body'] = $payload['draftBody'];
		}

		unset($payload['draftBody']);
		$payload['status'] = 'published';
		if ($this->objects->save(schema: 'page', object: $payload, id: $id) === null) {
			return self::saveFailed();
		}

		return [
			'result' => ['id' => $id, 'status' => 'published'],
			'audit' => ['schema' => 'page', 'id' => $id],
		];
	}//end publishPortalPage()

	/**
	 * Put a staff message in one portal account's inbox.
	 *
	 * @param array<string, mixed> $arguments `subjectRef`, `organisation`, `subject`, `body`.
	 * @param string               $uid       The acting user.
	 *
	 * @return array<string, mixed> The outcome.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-high-impact-writes-require-a-human-approval-gate
	 */
	private function sendPortalMessage(array $arguments, string $uid): array {
		if ($this->groups->isAdmin($uid) === false) {
			return self::forbidden();
		}

		$message = [
			'subjectRef' => self::text(arguments: $arguments, key: 'subjectRef'),
			'organisation' => self::text(arguments: $arguments, key: 'organisation'),
			'subject' => self::text(arguments: $arguments, key: 'subject'),
			'body' => self::text(arguments: $arguments, key: 'body'),
		];
		if (in_array('', $message, true) === true) {
			return self::error(code: 'invalid_argument', message: 'subjectRef, organisation, subject and body are required.');
		}

		$saved = $this->objects->save(
			schema: 'portalMessage',
			object: $message + ['read' => false, 'receivedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM)],
		);
		$id = (string)($saved['id'] ?? ($saved['@self']['id'] ?? ''));
		if ($saved === null || $id === '') {
			return self::saveFailed();
		}

		return [
			'result' => ['id' => $id, 'sent' => true],
			'audit' => ['schema' => 'portalMessage', 'id' => $id, 'organisation' => $message['organisation']],
		];
	}//end sendPortalMessage()

	/**
	 * Revoke every live portal session of one organisation. Answers counts
	 * only: never a session, a jti or claims.
	 *
	 * @param array<string, mixed> $arguments `organisation`.
	 * @param string               $uid       The acting admin.
	 *
	 * @return array<string, mixed> The outcome.
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-staff-write-action-is-a-curated-tool-governed-by-scope-and-reach
	 */
	private function revokeOrganisationSessions(array $arguments, string $uid): array {
		if ($this->groups->isAdmin($uid) === false) {
			return self::forbidden();
		}

		$organisation = self::text(arguments: $arguments, key: 'organisation');
		if ($organisation === '') {
			return self::error(code: 'invalid_argument', message: 'organisation is required.');
		}

		$outcome = $this->sessions->revokeAllForOrganisation($organisation, $uid);
		$counts = [
			'organisation' => $organisation,
			'revoked' => (int)($outcome['revoked'] ?? 0),
			'failed' => (int)($outcome['failed'] ?? 0),
			'complete' => (($outcome['complete'] ?? false) === true),
		];

		return [
			'result' => $counts,
			'audit' => ['schema' => 'portalSession', 'id' => '', 'organisation' => $organisation],
		];
	}//end revokeOrganisationSessions()

	/**
	 * The scope of a known tool; null for an unknown id.
	 *
	 * @param string $toolId The tool id.
	 *
	 * @return string|null The scope.
	 */
	private function scopeOf(string $toolId): ?string {
		foreach (self::catalogue() as $tool) {
			if ($tool['id'] === $toolId) {
				return $tool['scope'];
			}
		}

		return null;
	}//end scopeOf()

	/**
	 * A text argument, '' when absent or not a string.
	 *
	 * @param array<string, mixed> $arguments The arguments.
	 * @param string               $key       The argument name.
	 *
	 * @return string The trimmed text.
	 */
	private static function text(array $arguments, string $key): string {
		$value = ($arguments[$key] ?? '');
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()

	/**
	 * An object without OpenRegister's `@self` envelope, ready to save back.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private static function withoutEnvelope(array $row): array {
		unset($row['@self']);
		return $row;
	}//end withoutEnvelope()

	/**
	 * An error envelope.
	 *
	 * @param string $code    A machine-readable code.
	 * @param string $message A sentence for the agent.
	 *
	 * @return array{error: string, message: string}
	 */
	private static function error(string $code, string $message): array {
		return ['error' => $code, 'message' => $message];
	}//end error()

	/**
	 * The refusal for a caller without the authority the UI action needs.
	 *
	 * @return array{error: string, message: string}
	 */
	private static function forbidden(): array {
		return self::error(code: 'forbidden', message: 'You may not do this in portaliq.');
	}//end forbidden()

	/**
	 * The answer for an object that is absent or not visible to the caller.
	 *
	 * @return array{error: string, message: string}
	 */
	private static function notFound(): array {
		return self::error(code: 'not_found', message: 'No such object, or it is not visible to you.');
	}//end notFound()

	/**
	 * The answer when OpenRegister refused or failed the write.
	 *
	 * @return array{error: string, message: string}
	 */
	private static function saveFailed(): array {
		return self::error(code: 'save_failed', message: 'The change was not saved.');
	}//end saveFailed()

	/**
	 * The tool catalogue, without the approval flag getTools() adds.
	 *
	 * @return list<array<string, mixed>> Descriptors.
	 */
	private static function catalogue(): array {
		$text = ['type' => 'string'];
		return [
			[
				'id' => 'portaliq.trafficSummary',
				'name' => 'Traffic summary',
				'description' => 'Page views, sessions, visitors and engaged sessions of one portal over the last 7, 30, 90 or 365 days. Administrators only.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'portal' => $text,
						'days' => ['type' => 'string', 'enum' => TrafficReportController::SUMMARY_DAYS],
					],
					'required' => ['portal'],
				],
				'scope' => 'read',
				'reach' => 'instance',
				'readOnlyHint' => true,
				'codePath' => 'TrafficReportController::summary',
			],
			[
				'id' => 'portaliq.updatePortalContent',
				'name' => 'Update portal content',
				'description' => "Change a page's unpublished draft, a menu or a glossary term. A page draft is not visible on the "
					. "portal until it is published. Content editors only.",
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'schema' => ['type' => 'string', 'enum' => array_keys(self::CONTENT_FIELDS)],
						'id' => $text,
						'fields' => ['type' => 'object'],
					],
					'required' => ['schema', 'id', 'fields'],
				],
				'scope' => 'update',
				'reach' => 'instance',
				'idempotentHint' => true,
				'codePath' => 'OpenRegister objects API as used by the page editor',
			],
			[
				'id' => 'portaliq.updatePageRegions',
				'name' => 'Update page regions',
				'description' => "Set which widgets a portal shows in each region (header, footer and the others). Unknown regions are"
					. " dropped. Content editors only.",
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'portal' => $text,
						'regions' => ['type' => 'object'],
					],
					'required' => ['portal', 'regions'],
				],
				'scope' => 'update',
				'reach' => 'instance',
				'idempotentHint' => true,
				'codePath' => 'PortalRegionResolver::ordered over the portal object',
			],
			[
				'id' => 'portaliq.applyTheme',
				'name' => 'Apply theme',
				'description' => 'Choose the theme of a portal. A theme with contrast findings is refused unless acceptFindings is true. Administrators only.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'portal' => $text,
						'theme' => $text,
						'acceptFindings' => ['type' => 'boolean'],
					],
					'required' => ['portal', 'theme'],
				],
				'scope' => 'update',
				'reach' => 'instance',
				'idempotentHint' => true,
				'codePath' => 'PortalThemeController::update',
			],
			[
				'id' => 'portaliq.publishPortalPage',
				'name' => 'Publish portal page',
				'description' => 'Publish a page: its draft becomes the live content and the page is served on the portal. Content '
					. 'editors only, after a person approves.',
				'inputSchema' => ['type' => 'object', 'properties' => ['id' => $text], 'required' => ['id']],
				'scope' => 'update',
				'reach' => 'instance',
				'codePath' => 'page editor publish (draftBody to body, status published)',
			],
			[
				'id' => 'portaliq.sendPortalMessage',
				'name' => 'Send portal message',
				'description' => "Put a message in one resident's or supplier's portal inbox. Administrators only, after a person approves.",
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'subjectRef' => $text,
						'organisation' => $text,
						'subject' => $text,
						'body' => $text,
					],
					'required' => ['subjectRef', 'organisation', 'subject', 'body'],
				],
				'scope' => 'create',
				'reach' => 'external',
				'codePath' => 'portalMessage create through the OpenRegister objects API',
			],
			[
				'id' => 'portaliq.revokeOrganisationSessions',
				'name' => 'Revoke organisation sessions',
				'description' => "Sign out every portal user of one organisation, for example after a stolen device. Answers how many "
					. "sessions were revoked. Administrators only, after a person approves.",
				'inputSchema' => ['type' => 'object', 'properties' => ['organisation' => $text], 'required' => ['organisation']],
				'scope' => 'delete',
				'reach' => 'instance',
				'destructiveHint' => true,
				'codePath' => 'SessionAdminController::revokeOrganisation',
			],
		];
	}//end catalogue()
}//end class
