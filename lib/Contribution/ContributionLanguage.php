<?php

/**
 * Portaliq Contribution Language
 *
 * Makes a contributing app answer in the portal's language. A leaf app's
 * provider translates its labels with its own IL10N, and Nextcloud picks that
 * language per request: a forced language first, then the signed-in user's,
 * then the browser's `Accept-Language`. A portal request has no Nextcloud
 * user, and its `Accept-Language` is whatever sent it, so a Dutch portal could
 * get English section labels from learniq.
 *
 * The provider interface (`getContribution(array $subject)`) stays as it is:
 * leaf apps implement it, and a new argument would break every one of them.
 * Instead the request carries Nextcloud's own `forceLanguage` parameter, set
 * to the portal's language, for the duration of the provider call only. The
 * L10N factory reads that parameter whenever an IL10N is resolved, so a
 * provider that asks `IFactory::get('learniq')` or uses an injected (lazy)
 * IL10N during the call gets the portal's language. Afterwards the parameter
 * is put back as it was.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-the-portal-api-must-ask-contributing-apps-in-the-portals-language
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IConfig;
use OCP\IRequest;
use Throwable;

/**
 * Picks the portal's language and runs a provider call in it.
 *
 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-the-portal-api-must-ask-contributing-apps-in-the-portals-language
 */
class ContributionLanguage {
	/**
	 * The request parameter Nextcloud's L10N factory obeys before anything else.
	 */
	public const PARAMETER = 'forceLanguage';

	/**
	 * Constructor.
	 *
	 * @param IRequest       $request The current request.
	 * @param IConfig        $config  For an instance-wide `force_language`.
	 * @param PortalResolver $portals Resolves the portal the request is for.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IConfig $config,
		private readonly PortalResolver $portals,
	) {
	}//end __construct()

	/**
	 * The language this request's contributions should speak.
	 *
	 * The portal is the one the site names (`X-Portaliq-Portal`), else the
	 * one for the request's host, else the subject's organisation's. Of its
	 * declared `locales`, the one the request asks for wins (the site sends
	 * its chosen language as `Accept-Language`); otherwise the portal's
	 * first. No portal or no locales: '' and Nextcloud decides as before.
	 *
	 * @param array<string, mixed> $subject The subject (only `organisation` is read).
	 *
	 * @return string A language code, or '' to leave the choice to Nextcloud.
	 *
	 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-the-portal-api-must-ask-contributing-apps-in-the-portals-language
	 */
	public function languageFor(array $subject=[]): string {
		$locales = $this->portalLocales(organisation: (string)($subject['organisation'] ?? ''));
		if ($locales === []) {
			return '';
		}

		$asked = $this->askedLanguage();
		if ($asked !== '') {
			foreach ($locales as $locale) {
				if ($this->sameLanguage(locale: $locale, asked: $asked) === true) {
					return $locale;
				}
			}
		}

		return $locales[0];
	}//end languageFor()

	/**
	 * Run a call with the request's language forced to `$language`, and put
	 * the request back afterwards, also when the call throws.
	 *
	 * Nothing is forced when there is no language, when the administrator
	 * forced one for the whole instance, when the request already names one
	 * itself, or when the request object cannot take parameters (then the
	 * call runs exactly as it did before this class existed).
	 *
	 * @param string   $language The language, '' for none.
	 * @param callable $call     The provider call.
	 *
	 * @return mixed What the call returns.
	 *
	 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-the-portal-api-must-ask-contributing-apps-in-the-portals-language
	 */
	public function speak(string $language, callable $call): mixed {
		if ($language === '' || $this->languageAlreadyForced() === true
			|| method_exists($this->request, 'setUrlParameters') === false
		) {
			return $call();
		}

		$before = $this->request->urlParams;
		if (is_array($before) === false) {
			$before = [];
		}

		$this->request->setUrlParameters(array_merge($before, [self::PARAMETER => $language]));
		try {
			return $call();
		} finally {
			// The factory reads the merged parameters, so the forced value is
			// overwritten with null (getParam() then answers its default), not
			// merely left out.
			$this->request->setUrlParameters(array_merge($before, [self::PARAMETER => null]));
		}
	}//end speak()

	/**
	 * The portal's declared locales, non-empty strings only.
	 *
	 * @param string $organisation The subject's organisation, for the fallback lookup.
	 *
	 * @return array<int, string>
	 */
	private function portalLocales(string $organisation): array {
		try {
			$slug = trim($this->request->getHeader(CaseTypeVisibility::HEADER));
			if ($slug === '') {
				$slug = null;
			}

			$portal = $this->portals->resolve(request: $this->request, portalSlug: $slug);
			if ($portal === null) {
				$portal = $this->portals->resolveByOrganisation(organisation: $organisation);
			}
		} catch (Throwable) {
			// No portal to ask is no reason to lose the contributions.
			return [];
		}

		$locales = [];
		foreach ((array)($portal['locales'] ?? []) as $locale) {
			if (is_string($locale) === true && trim($locale) !== '') {
				$locales[] = trim($locale);
			}
		}

		return $locales;
	}//end portalLocales()

	/**
	 * The first language tag of `Accept-Language`, lower case, or ''.
	 *
	 * @return string
	 */
	private function askedLanguage(): string {
		$first = explode(',', $this->request->getHeader('Accept-Language'))[0];
		$first = explode(';', $first)[0];

		return strtolower(str_replace('_', '-', trim($first)));
	}//end askedLanguage()

	/**
	 * Whether a portal locale is the language asked for: the same tag, or
	 * the same primary language (`nl` and `nl-NL`).
	 *
	 * @param string $locale The portal locale.
	 * @param string $asked  The asked tag, lower case with hyphens.
	 *
	 * @return bool
	 */
	private function sameLanguage(string $locale, string $asked): bool {
		$locale = strtolower(str_replace('_', '-', $locale));
		if ($locale === $asked) {
			return true;
		}

		return explode('-', $locale)[0] === explode('-', $asked)[0];
	}//end sameLanguage()

	/**
	 * Whether a language is already forced: instance-wide by the
	 * administrator, or by the request itself (`?forceLanguage=`). Both win.
	 *
	 * @return bool
	 */
	private function languageAlreadyForced(): bool {
		if (is_string($this->config->getSystemValue('force_language', false)) === true) {
			return true;
		}

		$own = $this->request->getParam(self::PARAMETER);

		return is_string($own) === true && $own !== '';
	}//end languageAlreadyForced()
}//end class
