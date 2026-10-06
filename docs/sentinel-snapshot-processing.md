# Sentinel snapshot processing

Sentinel pushes still update the heartbeat immediately. The existing state hash
and force interval decide when status processing is needed.

## Reporting-server status

`PushServerUpdateJob` aggregates the reporting server's container statuses.
Primary destinations update the application's stored status; additional
servers update only their own pivot status. Complete snapshots can mark missing
destinations exited. Incomplete snapshots keep the existing positive-observation
safeguards. This path no longer calls the all-server SSH status action.

Health exclusions, restart-count tracking and existing recovery actions are
preserved. Recovery actions may still perform necessary SSH operations. The
separate SSH fallback path is unchanged.

## Coalescing

The receiving controller publishes one encrypted latest snapshot per server
under its existing cache lock. Snapshot data and a tiny publication marker have
a one-day retention; the marker avoids fetching large payloads while draining
legacy jobs.

`ProcessSentinelSnapshotJob` queues only a server reference. Laravel uniqueness
suppresses repeated pending wake-ups; its shared overlap lock serializes old
and new status processors. The uniqueness lease is five minutes so a lost queue
entry cannot strand status processing forever. Extended queue stalls can leave
small duplicate wake-ups; a processed-version marker makes these no-ops.

The job reads the latest snapshot when it begins processing. Completion is
serialized with publishers and schedules a deduplicated follow-up if a newer
version arrived during processing. Failures reset dispatch suppression so an
unchanged subsequent push can retry. Stale snapshots outside the heartbeat
grace window are ignored, while metrics history remains independent.

After a new accepted snapshot exists, legacy full-payload jobs are superseded
and return without applying old observations. They are acknowledged by normal
queue workers; no queue flush or manual deletion is required by this patch.

## Validation

Regression coverage includes primary/additional destination writes, missing
and incomplete snapshots, health exclusions and restart states, encrypted
storage, repeated pushes, concurrent arrivals, failed enqueue recovery, shared
overlap locks, stale observations, and orphan-lock recovery.
