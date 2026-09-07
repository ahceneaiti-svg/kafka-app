# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Event-driven user-management platform. An HTTP API writes users to PostgreSQL and
publishes a `USER_CREATED` event to Kafka; two independent consumer services react
asynchronously (welcome email, audit log). Runs locally with Docker Compose or on
Kubernetes via kind. Published images live at `docker.io/ahceneaiti/*:latest`.

GitHub: `ahceneaiti-svg/kafka-app` (branch `main`).

## Repository layout

| Path | Role |
|------|------|
| `user-service/` | Symfony 7.3 HTTP API. CRUD `/api/users`, Doctrine ORM → Postgres, publishes `USER_CREATED`. Image `php-api-user`. |
| `notification-service/` | Long-running Kafka consumer (`group.id` = `notification-service`) → sends welcome mail via MailHog. |
| `audit-service/` | Long-running Kafka consumer (`group.id` = `audit-service`) → writes a JSON audit line to stdout. |
| `postgres/` | Build context for image `postgresql-user` (Postgres 16 + `initdb/`). |
| `kafka/` | Build context for image `kafka-user` (**`apache/kafka:3.8.0`**, KRaft, single node). |
| `k8s/` | Kustomize manifests + `port-forward.sh` + `k8s/README.md` (step-by-step deploy guide). |
| `docker-compose.yml` | Full local stack (7 services). |
| `kind-config.yaml` | kind cluster with NodePort→host mappings 30080→8080, 30808→8090, 30825→8025. |

The three services are plain Symfony MicroKernel apps (no Flex). `notification-service`
and `audit-service` are near-identical copies — a change to the consumer skeleton
(`src/Messaging/KafkaConsumer.php`, `src/Command/ConsumeUserEventsCommand.php`,
`src/Kernel.php`, `config/`) usually needs applying to **both**.

## Architecture

### Event flow

```
POST /api/users
  → UserService::create()  : persist + flush (Postgres)
  → KafkaProducer::publish('USER_CREATED', <userId>, <data>)   topic "user-events"
  → HTTP 201  (only after a successful blocking flush)

topic "user-events"  ──┬──► notification-service  (own group.id)  → WelcomeMailer → MailHog
                       └──► audit-service         (own group.id)  → AuditRecorder → stdout
```

Distinct `group.id` per consumer = fan-out: both services receive every event
independently. Known limitation: DB write and Kafka publish are a **dual write**
with no transactional outbox.

### Message envelope (JSON)

```json
{ "eventType": "USER_CREATED",
  "occurredAt": "<ISO-8601>",
  "data": { "id", "email", "firstName", "lastName", "createdAt" } }
```

Kafka message **key** = user id. Header `eventType` is set. Consumers only act on
`eventType == "USER_CREATED"`; any other type is read and the offset advances.

### Kafka wrappers (`src/Messaging/`)

- **`KafkaProducer`** (user-service): `ext-rdkafka`, `enable.idempotence=true`,
  blocking `flush()` loop. If the flush does not complete it **throws**, so the API
  never returns 201 for an unpublished event.
- **`KafkaConsumer`** (both consumers): high-level consumer, `enable.auto.commit=false`.
  The command commits **only after** the handler succeeds; on handler failure it does
  not commit and the message is redelivered on the next poll.
- The consumer command implements `SignalableCommandInterface` → clean stop on
  `SIGTERM` (matters for `kubectl rollout` / `docker compose down`).

### Configuration

All runtime config is environment variables, resolved via
`#[Autowire('%env(...)%')]`:
`KAFKA_BROKERS`, `KAFKA_TOPIC`, `KAFKA_CONSUMER_GROUP`, `DATABASE_URL`,
`MAILER_DSN`, `MAILER_FROM`, `APP_SECRET`, `APP_ENV`.

| Context | Source |
|---------|--------|
| local `bin/console` / IDE | each service's `.env` (committed, dev defaults only) |
| Docker Compose | `environment:` blocks in `docker-compose.yml` |
| Kubernetes | `ConfigMap app-config` + `Secret app-secrets` (`k8s/05-config.yaml`), consumer `group.id` overridden per Deployment |

### Persistence

Postgres uses a PVC (survives redeploys). Kafka uses `emptyDir` / a compose volume —
**topic data is lost if the kafka pod/container restarts**.
`users` table: `id UUID` PK, unique `email`. Single migration
`user-service/migrations/Version20260907120000.php`.

## Common commands

### Docker Compose (full stack)

```bash
docker compose pull                 # get ahceneaiti/* images
docker compose up -d                # or: up --build -d  to rebuild from source
docker compose logs -f notification-service audit-service
docker compose down -v              # stop + wipe volumes
```

Endpoints: API `http://localhost:8080`, Kafka UI `http://localhost:8090`,
MailHog `http://localhost:8025`.

### Symfony console inside a service

```bash
docker compose exec user-service php bin/console <cmd>
# k8s:
kubectl -n user-platform exec -it deploy/user-service -- php bin/console <cmd>

# migrations
php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
php bin/console doctrine:migrations:status
```

Migrations also run automatically in the `initContainer` of the `user-service`
Deployment on every pod start.

### Inspect Kafka

```bash
# compose
docker compose exec kafka /opt/kafka/bin/kafka-console-consumer.sh \
  --bootstrap-server localhost:9092 --topic user-events --from-beginning \
  --property print.key=true --property print.headers=true

# k8s
kubectl -n user-platform exec deploy/kafka -- \
  /opt/kafka/bin/kafka-consumer-groups.sh --bootstrap-server localhost:9092 \
  --describe --all-groups
```

Scripts live in `/opt/kafka/bin/` in the kafka container.

### Kubernetes (kind) — manual, no build step

```bash
kind create cluster --config kind-config.yaml
kubectl apply -k k8s
kubectl -n user-platform wait --for=condition=available --timeout=300s deployment --all
curl -s http://localhost:8080/health

./k8s/port-forward.sh              # if cluster lacks the kind-config port mappings
kubectl delete -k k8s ; kind delete cluster --name user-platform
```

The cluster pulls `ahceneaiti/*:latest` from Docker Hub (`imagePullPolicy: Always`).
There is no `make` and no `kind load`. After publishing a new image:
`kubectl -n user-platform rollout restart deploy/<name>`.

### Build & publish images

```bash
docker build -t ahceneaiti/<name>:latest ./<service>
docker push  ahceneaiti/<name>:latest
```

Names: `php-api-user` (`./user-service`), `postgresql-user` (`./postgres`),
`kafka-user` (`./kafka`), `notification-service`, `audit-service`.

### Tests / lint

No test suite or linter is configured in this repo.

## Gotchas (hit while building this)

- **Composer resolution**: the Dockerfiles run
  `composer install/update ... --no-security-blocking`. The build environment's
  advisory database flags *every* Symfony version as vulnerable; without that flag
  resolution fails. `--no-audit` is not a valid `composer install` option — do not
  swap it back.
- **`composer.lock` is not committed.** The Docker build resolves fresh. To pin,
  run `composer install` per service and commit the locks.
- **rdkafka install is flaky**: Dockerfiles do `pecl channel-update pecl.php.net`
  then retry `pecl install rdkafka` (a bare `pecl install rdkafka` intermittently
  fails with "does not have REST xml available").
- **Kafka image**: `bitnami/kafka` was removed from Docker Hub — this uses
  `apache/kafka:3.8.0`, whose env vars are `KAFKA_*` (not `KAFKA_CFG_*`), with
  `KAFKA_LOG_DIRS=/var/lib/kafka/data`.
- **`docker push` is blocked** by the session's permission classifier. Ask the
  user to run pushes themselves.
- **Consumers tolerate a not-yet-ready broker** — `brokers are down` /
  `Unknown topic or partition` at startup is expected and self-heals (retry loop;
  topic auto-created on first publish).

## Git

Per the user's global rules: never commit without being asked, never `git push`
without an explicit request, check branch/remote before pushing, never
`--force` / `reset --hard` / `clean -fd` without confirmation.
