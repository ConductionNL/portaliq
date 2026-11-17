<?php

/**
 * Portaliq account hidden pages
 *
 * What the "Pages hidden for this client" field on a portal account's page
 * reads and saves: the pages the installed apps contribute for the account's
 * audience, each with whether it is hidden for this account, and the save of
 * `portalAccount.hiddenPages`. Only staff reach it; the account holder's own
 * self-service routes never write the list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Kernel\Contracts\AccountHiddenPagesInterface;
use OCA\Portaliq\Kernel\Contracts\PortalAccountLookupInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContributionRegistryInterface;
use OCA\Portaliq\Kernel\Contracts\PortalObjectWriterInterface;

/**
 * Lists and saves the pages hidden for one portal account.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
 */
class AccountHiddenPages implements AccountHiddenPagesInterface {
	/**
	 * The register accounts live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The account schema.
	 *
	 * @var string
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * A page key: `<app>:<pageId>`, the same shape PageChoice accepts.
	 *
	 * @var string
	 */
	private const PAGE_KEY = '/^[A-Za-z0-9_-]+:[^\s]+$/';

	/**
	 * Constructor.
	 *
	 * @param PortalAccountLookupInterface        $accounts Reads the account.
	 * @param PortalObjectWriterInterface         $writer   Writes the account's hidden pages.
	 * @param PortalContributionRegistryInterface $registry The contributions of the installed apps.
	 */
	public function __construct(
		private readonly PortalAccountLookupInterface $accounts,
		private readonly PortalObjectWriterInterface $writer,
		private readonly PortalContributionRegistryInterface $registry,
	) {
	}//end __construct()

	/**
	 * The portal account with this subject reference, or null.
	 *
	 * @param string $subjectRef The account's subject reference.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	public function accountBySubjectRef(string $subjectRef): ?array {
		if (trim($subjectRef) === '') {
			return null;
		}

		return $this->accounts->bySubjectRef(subjectRef: $subjectRef);
	}//end accountBySubjectRef()

	/**
	 * The pages the installed apps contribute for the account's audience,
	 * each with `hidden`, then any page the account has hidden that no
	 * installed app contributes now (`contributed` false), so it can still be
	 * shown again.
	 *
	 * @param array<string, mixed> $account The account.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	public function listFor(array $account): array {
		$hidden = array_flip($this->normalise(hidden: ($account['hiddenPages'] ?? null)));
		$pages  = (new ContributedPages(registry: $this->registry))->forAudience(audience: (string)($account['audience'] ?? ''));

		$out = [];
		foreach ($pages as $page) {
			if (isset($out[$page['page']]) === true) {
				continue;
			}

			unset($page['menu']);
			$page['hidden']       = isset($hidden[$page['page']]);
			$page['contributed']  = true;
			$out[$page['page']]   = $page;
		}

		foreach (array_keys($hidden) as $key) {
			if (isset($out[$key]) === false) {
				[$app, $pageId] = explode(':', (string)$key, 2);
				$out[$key]      = [
					'page'        => (string)$key,
					'app'         => $app,
					'pageId'      => $pageId,
					'label'       => $pageId,
					'appLabel'    => $app,
					'hidden'      => true,
					'contributed' => false,
				];
			}
		}

		return array_values($out);
	}//end listFor()

	/**
	 * Store the pages hidden for the account: well-formed `app:pageId` keys,
	 * each once.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param mixed                $hidden  The page keys.
	 *
	 * @return array<string, mixed>|null The saved account, or null when the write failed.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	public function save(array $account, mixed $hidden): ?array {
		$id = $this->identifierOf(account: $account);
		if ($id === '') {
			return null;
		}

		return $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: ['hiddenPages' => $this->normalise(hidden: $hidden)]
		);
	}//end save()

	/**
	 * The well-formed page keys, each once, in the order given.
	 *
	 * @param mixed $hidden The keys.
	 *
	 * @return list<string>
	 */
	private function normalise(mixed $hidden): array {
		if (is_array($hidden) === false) {
			return [];
		}

		$out = [];
		foreach ($hidden as $key) {
			if (is_string($key) === true && preg_match(self::PAGE_KEY, $key) === 1) {
				$out[$key] = true;
			}
		}

		return array_keys($out);
	}//end normalise()

	/**
	 * The account's identifier, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $account The account.
	 *
	 * @return string The identifier, empty when the row carries none.
	 */
	private function identifierOf(array $account): string {
		$self = ($account['@self'] ?? null);
		if (is_array($self) === false) {
			$self = [];
		}

		foreach ([($account['uuid'] ?? null), ($account['id'] ?? null), ($self['uuid'] ?? null), ($self['id'] ?? null)] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end identifierOf()
}//end class
