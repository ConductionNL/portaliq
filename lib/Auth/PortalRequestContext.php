<?php

/**
 * Portaliq Portal Request Context
 *
 * Request-scoped holder for the subject resolved by PortalAuthMiddleware.
 * Nextcloud builds a fresh DI container per request, so a single registered
 * instance is shared between the middleware (which sets the subject) and the
 * guarded controller (which reads it) within one request only. The subject is
 * always server-derived from the validated bearer — never from a client param.
 *
 * @category Auth
 * @package  OCA\Portaliq\Auth
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
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Auth;

/**
 * Carries the authenticated subject for the current request.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */
class PortalRequestContext
{

    /**
     * The resolved subject, or null before the middleware runs.
     *
     * @var array<string, mixed>|null
     */
    private ?array $subject = null;

    /**
     * Store the resolved subject.
     *
     * @param array<string, mixed> $subject The server-derived subject.
     *
     * @return void
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     */
    public function setSubject(array $subject): void
    {
        $this->subject = $subject;
    }//end setSubject()

    /**
     * The resolved subject, or null when unauthenticated.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/supplier-portal/tasks.md#T02
     */
    public function getSubject(): ?array
    {
        return $this->subject;
    }//end getSubject()
}//end class
