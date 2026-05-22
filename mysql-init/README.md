# Local InteLIS MySQL init files

This directory is mounted into the optional `intelis-mysql` container by
`docker-compose.local-lab.yml`.

Place an approved InteLIS dump here when running a local/demo install:

```text
mysql-init/
  01-intelis-dump.sql.gz
  99-create-readonly-user.sh
```

The MySQL image imports `*.sql`, `*.sql.gz`, and executable `*.sh` files on the
first container startup only. The included `99-create-readonly-user.sh` creates
the read-only user used by the app after the dump has been imported.

Do not commit real lab dumps. This directory intentionally ignores dump files.
