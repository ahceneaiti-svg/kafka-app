# syntax=docker/dockerfile:1
#
# postgresql-user: Postgres 16 with the user-platform database preset.
# Any *.sql / *.sh in /docker-entrypoint-initdb.d runs on first boot
# (only when the data directory is empty).

FROM postgres:16-alpine

ENV POSTGRES_DB=app \
    POSTGRES_USER=app \
    POSTGRES_PASSWORD=app \
    PGDATA=/var/lib/postgresql/data/pgdata

COPY initdb/ /docker-entrypoint-initdb.d/

HEALTHCHECK --interval=5s --timeout=5s --retries=10 \
    CMD pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB" || exit 1
