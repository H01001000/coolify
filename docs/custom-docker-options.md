# Custom Docker Options: supplementary groups

`--group-add` maps to the Compose `group_add` list. Repeat the option for each
supplementary group; both numeric GIDs and group names are supported. Values stay
strings, and duplicate groups are removed.

```text
--group-add 988 --group-add=audio --group-add render-group
```

```yaml
group_add:
  - "988"
  - audio
  - render-group
```

This preserves the image's primary user and group. It can grant a non-root
container user access to a bind-mounted resource owned by a host GID.

## Primary user

`--user` maps to the Compose `user` string. It supports a UID or user name,
optionally followed by a GID or group name. If repeated, the last value wins,
matching Docker.

```text
--user 1000:1000 --group-add 988
```

```yaml
user: "1000:1000"
group_add:
  - "988"
```

Use `--group-add` when you only need an extra group without changing the image's
primary user or group. Both options accept `--flag=value` and `--flag value`.
