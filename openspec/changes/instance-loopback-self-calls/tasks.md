# Tasks: instance-loopback-self-calls

- [x] **T1**: `lib/Service/InstanceLoopback.php`: configured address, absolute URL, one loopback retry with the original Host on a connect-phase failure, per-request memory, info and warning logs, upload streams rewound for the retry
  - PHPUnit `InstanceLoopbackTest` (15 tests)
  - Mutation: 23 mutants of the service, all killed (configured branch, Host header, retry on and off, response check, connect_time, message errno, cache, validation rules, rewind, logs, allow_local_address, web root, origin, query, method mapping)
- [x] **T2**: `PortalTaskGateway`, `PortalActionForwarder` and `AvailabilityProbe` pass a path to `InstanceLoopback` instead of calling the client with the absolute URL
  - PHPUnit `PortalTaskGatewayTest::testAnUnreachablePublicAddressFallsBackToTheLoopback`, `PortalActionForwarderTest::testTheForwardGoesThroughTheInstanceLoopback`, `::testAForwardThatReachesNoAddressDegradesToNull`, `AvailabilityProbeTest::testAnUnreachablePublicAddressStillProbesThroughTheLoopback`
  - Mutation: a fixed method, a full URL instead of the path, the old absolute route, and no retry each fail a call-site test
- [x] **T3**: admin settings: `SettingsService` stores a validated `internal_base_url` (administrators only), `AdminRoot.vue` section "Calls to this server", nl strings
  - PHPUnit `SettingsServiceTest::testTheInternalAddressIsValidatedBeforeItIsStored`
- [x] **T4**: docs: `docs/operations/calls-to-this-server.md`
- [ ] **T5**: live: on :8090 remove the Apache `Listen 8090` workaround and check `/portal/api/tasks` answers 200 (coordinator, after merge)
