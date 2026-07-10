<?php

/**
 * Portaliq Portal Organisation Config Service
 *
 * Resolves the white-label `RUNTIME_CONFIG` PortalPageController injects for
 * the public, unauthenticated `/portal` shell (portal-white-label-runtime-config):
 * the visitor has no bearer yet, so the tenant is identified by a `?org={slug}`
 * query parameter (design.md, option 2 — no routing rework) and resolved
 * against OpenRegister's own Organisation entity (ADR-022 — no new parallel
 * client; the same entity every session's `organisation` claim already points
 * at). Per-tenant PORTAL presentation overrides (theme, logo, feature flags,
 * allowed embed origins) that Organisation itself does not carry live in this
 * app's own config (`IAppConfig`), keyed by the Organisation's uuid.
 *
 * Fails closed to a safe, NEUTRAL default on every unresolved path (no `org`
 * param, unknown slug, OpenRegister unavailable) — NEVER another tenant's
 * branding, and NEVER `frame-ancestors: *`.
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
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#1.2
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves per-tenant white-label presentation for the public portal shell.
 *
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#1.2
 */
class PortalOrganisationConfigService
{
    /**
     * OpenRegister's Organisation mapper (core entity — ADR-022, no new
     * parallel client; the same entity every session's `organisation` claim
     * already points at).
     */
    private const ORGANISATION_MAPPER = 'OCA\\OpenRegister\\Db\\OrganisationMapper';

    /**
     * IAppConfig key prefix for a per-organisation presentation override
     * blob (JSON), keyed by the Organisation's uuid.
     */
    private const CONFIG_KEY_PREFIX = 'org_presentation_';

    /**
     * The safe, neutral default — used whenever no tenant can be resolved.
     * NEVER another tenant's branding, NEVER a permissive embed policy.
     *
     * @var array<string, mixed>
     */
    private const NEUTRAL_DEFAULT = [
        'organisationName'    => 'Portaliq',
        'organisationSlug'    => '',
        'theme'               => 'default',
        'logo'                => null,
        'idp'                 => null,
        'featureFlags'        => [],
        'allowedEmbedOrigins' => [],
        // Portal-wide (not per-tenant) SPA config — kept here so the
        // resolved shape is always complete, regardless of which branch
        // (neutral default vs. a resolved Organisation) produced it.
        'apiBase'             => '/index.php/apps/portaliq/portal/api',
        'audience'            => 'supplier',
        'locale'              => 'nl',
    ];

    /**
     * Locales the portal SPA ships a translation bundle for
     * (`src/portal/i18n/{locale}.json`). Anything else falls back to `nl`
     * (the current de-facto default) — never a blank/unsupported locale.
     */
    private const SUPPORTED_LOCALES = ['nl', 'en'];

    /**
     * Constructor.
     *
     * @param ContainerInterface $container For resolving OpenRegister's mapper.
     * @param IAppConfig         $appConfig Per-organisation presentation overrides.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the white-label presentation for a tenant, by slug.
     *
     * An empty slug, an unknown slug, or an unreachable OpenRegister all
     * resolve to the same neutral default (design.md) — never a 500, never
     * another tenant's branding.
     *
     * @param string $orgSlug The `?org=` query parameter value (may be empty).
     * @param string $locale  The resolved visitor locale (portal-spa-i18n-locale-support),
     *                        e.g. from `Accept-Language`; an unsupported value
     *                        falls back to `nl`.
     *
     * @return array<string, mixed> `{organisationName, organisationSlug, theme,
     *                               logo, idp, featureFlags, allowedEmbedOrigins,
     *                               apiBase, audience, locale}`.
     *
     * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#1.2
     * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#1.3
     * @spec openspec/changes/portal-spa-i18n-locale-support/tasks.md#2.2
     */
    public function resolve(string $orgSlug, string $locale='nl'): array
    {
        $locale = $this->normaliseLocale(locale: $locale);

        if ($orgSlug === '') {
            $default           = self::NEUTRAL_DEFAULT;
            $default['locale'] = $locale;
            return $default;
        }

        $organisation = $this->findOrganisationBySlug(slug: $orgSlug);
        if ($organisation === null) {
            $default           = self::NEUTRAL_DEFAULT;
            $default['locale'] = $locale;
            return $default;
        }

        $uuid = $organisation['uuid'];
        $name = $organisation['name'];
        if ($name === '') {
            $name = self::NEUTRAL_DEFAULT['organisationName'];
        }

        $overrides = $this->presentationOverrides(organisationUuid: $uuid);

        $featureFlags = [];
        if (is_array(($overrides['featureFlags'] ?? null)) === true) {
            $featureFlags = $overrides['featureFlags'];
        }

        return [
            'organisationName'    => $name,
            'organisationSlug'    => $orgSlug,
            'theme'               => (string) ($overrides['theme'] ?? self::NEUTRAL_DEFAULT['theme']),
            'logo'                => ($overrides['logo'] ?? self::NEUTRAL_DEFAULT['logo']),
            // The real eHerkenning/DigiD IdP wiring is supplier-portal T02/T03
            // (OpenConnector) — a placeholder passthrough only, by design.
            'idp'                 => ($overrides['idp'] ?? self::NEUTRAL_DEFAULT['idp']),
            'featureFlags'        => $featureFlags,
            'allowedEmbedOrigins' => $this->allowedEmbedOrigins(overrides: $overrides),
            'apiBase'             => self::NEUTRAL_DEFAULT['apiBase'],
            'audience'            => (string) ($overrides['audience'] ?? self::NEUTRAL_DEFAULT['audience']),
            'locale'              => $locale,
        ];
    }//end resolve()

    /**
     * Normalise a raw locale value (e.g. the first `Accept-Language` tag) to
     * one of `SUPPORTED_LOCALES`; anything else falls back to `nl` (the
     * current de-facto default) — never an unsupported/blank locale.
     *
     * @param string $locale The raw locale candidate.
     *
     * @return string
     *
     * @spec openspec/changes/portal-spa-i18n-locale-support/tasks.md#2.2
     */
    private function normaliseLocale(string $locale): string
    {
        $short = strtolower(substr($locale, 0, 2));
        if (in_array($short, self::SUPPORTED_LOCALES, true) === true) {
            return $short;
        }

        return 'nl';
    }//end normaliseLocale()

    /**
     * Normalise the `allowedEmbedOrigins` override to a list of non-empty
     * strings. A malformed or absent override yields the empty list — the
     * CSP builder treats that as `frame-ancestors 'none'`, never `'*'`.
     *
     * @param array<string, mixed> $overrides The raw per-organisation overrides.
     *
     * @return array<int, string>
     *
     * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#3.2
     */
    private function allowedEmbedOrigins(array $overrides): array
    {
        $raw = ($overrides['allowedEmbedOrigins'] ?? []);
        if (is_array($raw) === false) {
            return [];
        }

        $origins = [];
        foreach ($raw as $origin) {
            if (is_string($origin) === true && $origin !== '') {
                $origins[] = $origin;
            }
        }

        return $origins;
    }//end allowedEmbedOrigins()

    /**
     * Read the per-organisation presentation override blob from IAppConfig,
     * or an empty array when unset/malformed (falls back to defaults).
     *
     * @param string $organisationUuid The Organisation's uuid.
     *
     * @return array<string, mixed>
     */
    private function presentationOverrides(string $organisationUuid): array
    {
        if ($organisationUuid === '') {
            return [];
        }

        $json = $this->appConfig->getValueString(
            Application::APP_ID,
            self::CONFIG_KEY_PREFIX.$organisationUuid,
            '{}'
        );

        try {
            $decoded = json_decode($json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [];
        }

        if (is_array($decoded) === false) {
            return [];
        }

        return $decoded;
    }//end presentationOverrides()

    /**
     * Resolve an Organisation by slug via OpenRegister's own mapper. Never
     * throws — an unknown slug or an unreachable/absent OpenRegister both
     * return null (fail-closed to the neutral default upstream).
     *
     * @param string $slug The organisation slug.
     *
     * @return array{uuid: string, name: string}|null
     */
    private function findOrganisationBySlug(string $slug): ?array
    {
        $mapper = $this->organisationMapper();
        if ($mapper === null || method_exists($mapper, 'findBySlug') === false) {
            return null;
        }

        try {
            $organisation = $mapper->findBySlug($slug);
        } catch (Throwable $e) {
            // Unknown slug (DoesNotExistException) or any other lookup
            // failure — both resolve to "no tenant found" upstream.
            $this->logger->debug('Portaliq: organisation slug not resolved', ['slug' => $slug, 'reason' => $e->getMessage()]);
            return null;
        }

        if (is_object($organisation) === false
            || method_exists($organisation, 'getUuid') === false
            || method_exists($organisation, 'getName') === false
        ) {
            return null;
        }

        $name = $organisation->getName();

        return [
            'uuid' => (string) $organisation->getUuid(),
            'name' => (string) ($name ?? ''),
        ];
    }//end findOrganisationBySlug()

    /**
     * Resolve OpenRegister's OrganisationMapper, or null when unavailable
     * (OpenRegister not installed, or the container cannot construct it).
     *
     * @return object|null
     */
    private function organisationMapper(): ?object
    {
        try {
            $mapper = $this->container->get(self::ORGANISATION_MAPPER);
        } catch (Throwable $e) {
            $this->logger->debug('Portaliq: OrganisationMapper unavailable', ['reason' => $e->getMessage()]);
            return null;
        }

        if (is_object($mapper) === true) {
            return $mapper;
        }

        return null;
    }//end organisationMapper()
}//end class
