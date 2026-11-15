# Tasks: loopback-tries-the-address-php-was-reached-on

- [x] **T1**: `lib/Service/LoopbackOrigins.php` lists the retry origins; `InstanceLoopback` tries each in turn after a connect-phase failure and keeps the one that answered
  - PHPUnit `LoopbackOriginsTest` (3 tests), `InstanceLoopbackTest::testTheAddressPhpWasReachedOnIsTriedFirstAndKept`, `::testEveryOriginIsTriedAndNamedWhenNoneAnswers`, `::testAnOriginThatTimedOutAfterConnectingEndsTheRetries`
- [x] **T2**: docs: `docs/operations/calls-to-this-server.md` describes the order and the `internal_base_url` setting for a sidecar on 8080
