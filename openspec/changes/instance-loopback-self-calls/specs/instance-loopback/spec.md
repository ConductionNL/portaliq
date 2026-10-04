## ADDED Requirements

### Requirement: Every call to this instance goes through one loopback service

Every server-to-server request portaliq sends to its own Nextcloud instance MUST go through `InstanceLoopback`. This covers the task gateway (list, detail, complete, create), the endpoint-action forwarder and the availability probe. A caller MUST pass a path on this instance, never a full URL. The service MUST keep the caller's headers and MUST NOT add credentials of its own.

#### Scenario: The task list loads behind a port mapping
- GIVEN the instance's public address is `http://localhost:8090` and nothing listens on 8090 inside the server
- WHEN a signed-in resident opens "Mijn taken"
- THEN the task list loads from openregister through the loopback
- AND the request carries the `X-Portal-Subject` assertion and no Authorization header
- @e2e exclude pinned by `PortalTaskGatewayTest::testAnUnreachablePublicAddressFallsBackToTheLoopback`; the live check on :8090 without the Apache workaround follows the merge

#### Scenario: An endpoint action uses the same path
- GIVEN an action declares an instance-local endpoint
- WHEN the resident runs it
- THEN the forwarder hands the endpoint path and method to `InstanceLoopback`
- @e2e exclude pinned by `PortalActionForwarderTest::testTheForwardGoesThroughTheInstanceLoopback`

#### Scenario: The availability probe does not report a reachable portal down
- GIVEN the public address does not answer from inside the server
- WHEN the probe checks a portal
- THEN it reaches the site and the health check on the loopback and reports the portal available
- @e2e exclude pinned by `AvailabilityProbeTest::testAnUnreachablePublicAddressStillProbesThroughTheLoopback`

### Requirement: A transport failure on the absolute URL is retried once on the loopback

Without a configured address the service MUST call the absolute URL first. When that call fails before a connection exists (cURL 5, 6, 7, 35, or 28 with no connection made), the service MUST retry once on `http://127.0.0.1` with the same path and query and the original `Host` header. It MUST log the fallback once at info, keep the loopback for the rest of the request, and never remember it across requests. When the loopback fails too, it MUST log one warning naming both failures and surface the first failure.

#### Scenario: One retry with the original Host
- GIVEN the absolute URL fails with cURL error 7
- WHEN portaliq calls its own instance
- THEN it calls `http://127.0.0.1` with the same path and `Host: localhost:8090`
- AND the next call in the same request goes to the loopback directly
- @e2e exclude pinned by `InstanceLoopbackTest::testATransportFailureIsRetriedOnceOnTheLoopbackWithTheOriginalHost`

#### Scenario: Both addresses fail
- GIVEN neither the absolute URL nor the loopback answers
- WHEN portaliq calls its own instance
- THEN one warning names both failures and the caller sees the first failure
- @e2e exclude pinned by `InstanceLoopbackTest::testWhenBothFailTheOriginalFailureSurfacesWithAWarning`

### Requirement: A real HTTP answer is never retried

Any HTTP response, whatever its status, MUST be returned as it is. A failure that carries a response MUST NOT be retried. A timeout after the connection was made MUST NOT be retried, because the request may already have been applied.

#### Scenario: A 502 comes back as a 502
- GIVEN the absolute URL answers 401, 404, 500 or 502
- WHEN portaliq calls its own instance
- THEN that answer is returned and no second request is sent
- @e2e exclude pinned by `InstanceLoopbackTest::testAnHttpErrorStatusIsNeverRetried` and `::testAFailureCarryingAResponseIsNeverRetried`

#### Scenario: A slow answer is not sent twice
- GIVEN the connection was made and the answer timed out
- WHEN portaliq completes a task
- THEN the completion is not sent again
- @e2e exclude pinned by `InstanceLoopbackTest::testOnlyATimeoutBeforeTheConnectionIsRetried`

### Requirement: An administrator can name the internal address

An administrator MAY set `internal_base_url` in the app config, in the admin settings or with occ. A valid value is an http or https address with a host, an optional port and an optional plain path (the web root). It MUST NOT carry credentials, a query, a fragment, percent-encoding, backslashes, whitespace, or `.` or `..` segments. A valid address MUST win over the absolute URL, carry the original `Host` header, and MUST NOT fall back to the loopback. The admin settings MUST refuse an invalid address and keep the stored one. An invalid stored value MUST be ignored with one warning per request.

#### Scenario: The configured address wins
- GIVEN `internal_base_url` is `http://nextcloud-app:8080`
- WHEN portaliq calls its own instance
- THEN it calls `http://nextcloud-app:8080` with the same path and the public `Host`
- @e2e exclude pinned by `InstanceLoopbackTest::testAConfiguredInternalBaseUrlWins` and `::testAConfiguredBaseUrlReplacesTheWebRoot`

#### Scenario: An invalid address is refused
- GIVEN an administrator enters `http://user:pw@evil/../x`
- WHEN they save it
- THEN the settings answer that it was refused and the stored address stays
- @e2e exclude pinned by `SettingsServiceTest::testTheInternalAddressIsValidatedBeforeItIsStored` and `InstanceLoopbackTest::testAnInvalidConfiguredUrlIsIgnoredWithAWarning`
