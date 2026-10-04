# Tasks: instance-loopback-self-calls

- [x] **T1**: `lib/Service/InstanceLoopback.php`: configured address, absolute URL, one loopback retry with the original Host on a connect-phase failure, per-request memory, info and warning logs, upload streams rewound for the retry
  - `lib/Service/InternalBaseUrl.php` reads, validates and stores the address; `InstanceLoopback` uses only its validated value
  - PHPUnit `InstanceLoopbackTest` (15 tests), `InternalBaseUrlTest` (4 tests)
  - Mutation: 31 mutants of `InstanceLoopback`, `InternalBaseUrl` and the settings controller, all killed (configured branch, Host header, retry on and off, response check, connect_time, context and message errno, cache, every validation rule, store without validation, rewind, both logs, allow_local_address, web root, origin, query, method mapping, admin-only display, refusal flag)
- [x] **T2**: `PortalTaskGateway`, `PortalActionForwarder` and `AvailabilityProbe` pass a path to `InstanceLoopback` instead of calling the client with the absolute URL
  - PHPUnit `PortalTaskGatewayTest::testAnUnreachablePublicAddressFallsBackToTheLoopback`, `PortalActionForwarderTest::testTheForwardGoesThroughTheInstanceLoopback`, `::testAForwardThatReachesNoAddressDegradesToNull`, `AvailabilityProbeTest::testAnUnreachablePublicAddressStillProbesThroughTheLoopback`
  - Mutation: a fixed method, a full URL instead of the path, the old absolute route, and no retry each fail a call-site test
- [x] **T3**: admin settings: `SettingsController` shows the address to administrators and stores it through `InternalBaseUrl` (the write is admin-only), `AdminRoot.vue` section "Calls to this server", nl strings
  - PHPUnit `InternalBaseUrlTest::testTheAdminSettingsStoreAndShowTheAddress`
- [x] **T4**: docs: `docs/operations/calls-to-this-server.md`
- [ ] **T5**: live: on :8090 remove the Apache `Listen 8090` workaround and check `/portal/api/tasks` answers 200 (coordinator, after merge)
