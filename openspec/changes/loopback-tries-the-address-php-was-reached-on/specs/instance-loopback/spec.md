## MODIFIED Requirements

### Requirement: A transport failure on the absolute URL is retried on the addresses this server answers on

Without a configured address the service MUST call the absolute URL first. When that call fails before a connection exists (cURL 5, 6, 7, 35, or 28 with no connection made), the service MUST retry with the same path and query and the original `Host` header on, in order: the address and port the web server took the current request on (`SERVER_ADDR`, `SERVER_PORT`), `127.0.0.1` on that port, the origin of `overwrite.cli.url`, and `http://127.0.0.1`, leaving out the absolute URL's own origin and duplicates. It MUST move to the next address only after a connect-phase failure. It MUST log the fallback once at info naming the address that answered, keep that address for the rest of the request, and never remember it across requests. When no address answers, it MUST log one warning naming every failure and surface the first failure.

#### Scenario: A web server on 8080 next to PHP-FPM
- GIVEN the absolute URL does not answer from inside the server and nothing listens on port 80
- AND the web server took the request on `10.1.2.3:8080`
- WHEN portaliq calls its own instance
- THEN it calls `http://10.1.2.3:8080` with the same path and the original `Host`
- AND the next call in the same request goes there directly
- @e2e exclude pinned by `InstanceLoopbackTest::testTheAddressPhpWasReachedOnIsTriedFirstAndKept` and `LoopbackOriginsTest::testAWebRequestTriesTheServerAddressFirst`

#### Scenario: One retry with the original Host
- GIVEN the absolute URL fails with cURL error 7 and PHP was not reached through a web server
- WHEN portaliq calls its own instance
- THEN it calls `http://127.0.0.1` with the same path and `Host: localhost:8090`
- @e2e exclude pinned by `InstanceLoopbackTest::testATransportFailureIsRetriedOnceOnTheLoopbackWithTheOriginalHost` and `LoopbackOriginsTest::testWithoutAWebServerOnlyTheConfiguredAndLocalOriginsRemain`

#### Scenario: No address answers
- GIVEN neither the absolute URL nor any loopback address answers
- WHEN portaliq calls its own instance
- THEN one warning names every failure and the caller sees the first failure
- @e2e exclude pinned by `InstanceLoopbackTest::testEveryOriginIsTriedAndNamedWhenNoneAnswers` and `::testWhenBothFailTheOriginalFailureSurfacesWithAWarning`

#### Scenario: A loopback address that connected is not followed by another
- GIVEN a loopback address connected and then timed out
- WHEN portaliq completes a task
- THEN no further address is tried and the completion is not sent again
- @e2e exclude pinned by `InstanceLoopbackTest::testAnOriginThatTimedOutAfterConnectingEndsTheRetries`
