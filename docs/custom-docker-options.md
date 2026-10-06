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
