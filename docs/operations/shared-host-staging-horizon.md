# Shared-host staging Horizon worker profile

## Purpose and scope

Multiple low-traffic Engage Core staging installations may share one small host.
With Horizon primary supervisors in `auto` balancing mode, the default minimum
of one worker **per queue** can keep many PHP CLI processes resident even when
very little work is arriving. `maxProcesses` is not a practical hard ceiling
below the per-queue minimum total in that setup.

Engage Core therefore uses a conservative **staging-only** primary supervisor
profile: `balance => false`, `minProcesses => 1`, with the existing staging
primary worker budget as `maxProcesses` (currently two by default when the
staging total is three and bulk is one). A single worker pool listens to all
configured primary queues in order. Bulk and media processing remain in
separate supervisors, with their existing connections, queue inventory,
timeouts, and limits. Local and production configurations are unchanged.

The only code behavior change is the **staging primary supervisor's** balancing
mode and its explicit minimum worker count. This is not an Artist Sites worker
provisioning system, a shared multi-client Laravel worker, or an attempt to
change production capacity.

## Queue ordering / fairness tradeoff

With balancing disabled, Laravel processes the configured primary queue list
**in order**. If earlier queues continuously contain work, later queues can
experience significant delays. The existing primary queue list is deliberately
preserved by this batch; no messaging priority policy is silently introduced.
Use this profile only where expected traffic is low enough for that tradeoff.
During the rollout, check queue wait times and real processing of reminders,
webhooks, confirmations, email/SMS, and general/default work. If latency is
unacceptable, revert the balancing mode and assess a separately prioritized
supervisor or more host resources. Do not assume the small pool is suitable
for higher-volume production installations.

## Configuration / rollback

- Environment: `APP_ENV=staging` only.
- Default staging primary balance: `false` (a shared ordered queue pool).
- Optional per-installation override: `HORIZON_STAGING_PRIMARY_BALANCE=auto`
  restores the previous automatic, per-queue balancing behavior.
- Existing `HORIZON_STAGING_MAX_PROCESSES` and
  `HORIZON_STAGING_BULK_MAX_PROCESSES` still determine the main and bulk process
  budgets. `minProcesses` on the staging primary pool remains one.
- The `HORIZON_SUPERVISOR_1_QUEUES` contract and dedicated bulk/media queues
  are unchanged. Ensure every dispatched queue is consumed by the intended
  supervisor; run the existing queue-contract behavior tests.

Environment changes require restarting the applicable Horizon service, after
checking whether jobs are currently active. Do **not** edit application code on
staging or production; deploy committed development changes using the normal
push/pull process. Do not apply one client's queue settings to another client.

## One-installation-at-a-time rollout

1. Test locally: `php -l config/horizon.php` and
   `php artisan test tests/Feature/Messaging/QueueContractTest.php --no-ansi`.
   Check the `staging` config resolves to a false `balance`, minimum one, the
   expected maximum, and the complete current primary queue inventory. Verify
   `production` and `local` still resolve to the previous modes.
2. Commit only the Horizon configuration and this document. Push the commit.
   Pull it to one staging installation via the normal deployment process.
3. Before restarting its supervised Horizon process, record `free -h`,
   `vmstat`, total Horizon workers, queued-job backlog/ages, failed jobs, and
   any active long-running work. Don't interrupt active media work.
4. On that installation confirm Laravel's **effective** Horizon staging config
   after deployment (including whether config is cached). If config caching is
   used, rebuild it via the established deployment procedure.
5. Restart **only that installation's** supervised Horizon program when safe.
   The existing deployment practice may include `php artisan queue:restart`
   before `sudo supervisorctl restart <specific-program>`. Do not use a
   global Supervisor restart, and do not stop Redis/MySQL.
6. Check the new primary workers really use `--balance=` without `auto`, and
   inspect actual worker count, elapsed job wait, failures, and host memory.
   Check representative queued jobs, especially time-sensitive queues.
7. If healthy, repeat for the next staging installation. Record before/after
   memory; do not attribute reclaimed RSS directly to physical RAM savings.

## Rapid rollback

If the staging primary supervisor causes unacceptable queue delay, set
`HORIZON_STAGING_PRIMARY_BALANCE=auto` **for that staging installation only**;
then reload/restart just its Horizon program safely using normal operational
procedures. That returns to the previous multi-queue balancing behavior,
including its per-queue idle processes. A subsequent source rollback can be
committed if the shared-host profile proves unsuitable.

No migration, queue purge, database modification, or code change on deployed
servers is part of this rollout. Never clear queues merely to obtain a clean
worker-count measurement.