<?php

/**
 * Portaliq Contribution Controller
 *
 * Protected portal API: returns the aggregated portal contributions the
 * authenticated subject may see (the declarative manifest — collections +
 * actions each contributing app registered). Guarded by PortalAuthMiddleware via
 * the PortalProtected marker, so it fails closed (401) without a valid session;
 * the subject is read from the request-scoped context, never from a client
 * param. Reading the actual objects in each collection through OpenRegister is
 * the next slice (T05).
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Auth\PortalRequestContext;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\PortalObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves the authenticated subject's aggregated portal contributions.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */
class ContributionController extends Controller implements PortalProtected
{
    /**
     * Constructor.
     *
     * @param IRequest                   $request  The request object.
     * @param PortalContributionRegistry $registry The contribution aggregator.
     * @param PortalRequestContext       $context  The request-scoped subject holder.
     */
    public function __construct(
        IRequest $request,
        private readonly PortalContributionRegistry $registry,
        private readonly PortalRequestContext $context,
        private readonly PortalObjectReader $reader,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the contributions the authenticated subject may see.
     *
     * The route is marked #[PublicPage] because portal subjects are not
     * Nextcloud users; PortalAuthMiddleware enforces the bearer session and
     * fails closed before this method runs.
     *
     * @return JSONResponse The aggregated contribution manifest.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T04
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        $subject = $this->context->getSubject();
        if ($subject === null) {
            // Defensive: the middleware should already have failed closed.
            return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse($this->registry->aggregateFor($subject));
    }//end index()

    /**
     * Read the objects in one contribution collection, scoped to the subject.
     *
     * Authorises first: the (register, schema) must appear in one of the
     * subject's own contributions, so a subject can only read collections its
     * apps granted it. The subject reference is taken from the request context,
     * never the client.
     *
     * @param string $register The register of the collection.
     * @param string $schema   The schema of the collection.
     *
     * @return JSONResponse The subject's rows, or 401 / 403.
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T05
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function collection(string $register, string $schema): JSONResponse
    {
        $subject = $this->context->getSubject();
        if ($subject === null) {
            return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
        }

        $collection = $this->authorisedCollection(subject: $subject, register: $register, schema: $schema);
        if ($collection === null) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }

        $objects = $this->reader->readCollection(
            register: $register,
            schema: $schema,
            scopeField: (string) ($collection['scopeField'] ?? 'subjectRef'),
            subjectRef: (string) ($subject['subjectRef'] ?? ''),
            organisation: (string) ($subject['organisation'] ?? '')
        );

        return new JSONResponse(['register' => $register, 'schema' => $schema, 'objects' => $objects]);
    }//end collection()

    /**
     * Find the collection matching (register, schema) in the subject's
     * aggregated contributions, or null when the subject is not entitled to it.
     *
     * @param array<string, mixed> $subject  The resolved subject.
     * @param string               $register The requested register.
     * @param string               $schema   The requested schema.
     *
     * @return array<string, mixed>|null
     */
    private function authorisedCollection(array $subject, string $register, string $schema): ?array
    {
        $aggregate = $this->registry->aggregateFor($subject);
        foreach (($aggregate['contributions'] ?? []) as $contribution) {
            foreach (($contribution['collections'] ?? []) as $collection) {
                if (($collection['register'] ?? '') === $register && ($collection['schema'] ?? '') === $schema) {
                    return $collection;
                }
            }
        }

        return null;
    }//end authorisedCollection()
}//end class
