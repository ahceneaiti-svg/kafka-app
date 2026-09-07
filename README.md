# User Platform — API Symfony + Kafka + Kubernetes (kind)

Plateforme évènementielle de gestion d'utilisateurs.

```
POST /api/users ──► user-service ──► Postgres (write)
                          │
                          └──► Kafka topic "user-events"  (évènement USER_CREATED)
                                       │
                    ┌──────────────────┴───────────────────┐
                    ▼                                        ▼
        notification-service                         audit-service
      (groupe notification-service)              (groupe audit-service)
        envoie un mail de bienvenue                écrit une ligne d'audit
             via MailHog
```

Chaque consommateur a son propre `group.id` : les deux réagissent **en
parallèle et de façon asynchrone** au même évènement. Un consommateur qui
tombe rejoue le message (commit manuel après traitement réussi).

## Composants

| Élément                | Rôle                                             | Image                    |
|------------------------|--------------------------------------------------|--------------------------|
| `user-service`         | API REST Symfony 7.3, publie `USER_CREATED`      | `php-api-user`           |
| `postgres`             | Stockage des utilisateurs                        | `postgresql-user`        |
| `kafka`                | Bus d'évènements (KRaft, sans ZooKeeper)         | `kafka-user`             |
| `notification-service` | Consommateur → mail de bienvenue                 | `notification-service`   |
| `audit-service`        | Consommateur → journal d'audit                   | `audit-service`          |
| `mailhog`              | SMTP de test + UI web                            | `mailhog/mailhog`        |
| `kafka-ui`             | Interface web pour inspecter topics / messages   | `kafbat/kafka-ui`        |

## API

| Méthode | Route              | Description                          |
|---------|--------------------|-------------------------------------|
| POST    | `/api/users`       | Crée un utilisateur + `USER_CREATED` |
| GET     | `/api/users`       | Liste                               |
| GET     | `/api/users/{id}`  | Détail                              |
| DELETE  | `/api/users/{id}`  | Suppression                         |
| GET     | `/health`          | Sonde de vie                        |

Corps de `POST /api/users` :

```json
{ "email": "ada@example.com", "firstName": "Ada", "lastName": "Lovelace" }
```

---

## Option A — docker compose (le plus rapide)

```bash
make up            # build + démarre tout
# API      : http://localhost:8080
# Kafka UI : http://localhost:8090
# MailHog  : http://localhost:8025

curl -s -X POST http://localhost:8080/api/users \
  -H 'Content-Type: application/json' \
  -d '{"email":"ada@example.com","firstName":"Ada","lastName":"Lovelace"}' | jq

docker compose logs -f notification-service audit-service
make down           # stoppe + supprime les volumes
```

---

## Option B — Kubernetes local sur kind

Prérequis : `docker`, `kind`, `kubectl`.

```bash
make kind-up        # crée le cluster "user-platform" (ports 8080 + 8025 mappés)
make deploy         # build images -> kind load -> kubectl apply -k k8s
make k8s-status

# API      : http://localhost:8080
# Kafka UI : http://localhost:8090   (NodePort 30808)
# MailHog  : http://localhost:8025

curl -s -X POST http://localhost:8080/api/users \
  -H 'Content-Type: application/json' \
  -d '{"email":"grace@example.com","firstName":"Grace","lastName":"Hopper"}' | jq

kubectl -n user-platform logs -l app=notification-service -f
kubectl -n user-platform logs -l app=audit-service -f

make undeploy       # supprime les ressources k8s
make kind-down      # supprime le cluster
```

Les migrations Doctrine tournent dans un `initContainer` du Deployment
`user-service` avant chaque démarrage du pod.

**Kafka UI** : `http://localhost:8090` (topics, messages, groupes consumers, lag).
Si le cluster kind a été créé avant l'ajout du mapping de port, utiliser :

```bash
kubectl -n user-platform port-forward svc/kafka-ui 8090:8080
```

### Manifestes (`k8s/`)

`00-namespace` · `05-config` (ConfigMap + Secret) · `10-postgres`
(Deployment + Service + PVC) · `20-kafka` · `30-mailhog` · `40-user-service`
(Deployment + initContainer migrations + Service NodePort 30080) ·
`50-notification-service` · `60-audit-service` · `kustomization.yaml`.

---

## Publier les images sur un registry

`make push` construit puis pousse **php-api-user**, **postgresql-user**,
**kafka-user** vers `$REGISTRY` :

```bash
make push REGISTRY=docker.io/mon-compte
# ou : ghcr.io/mon-org, registry.gitlab.com/mon-groupe/mon-projet, localhost:5000 ...

make push-all REGISTRY=docker.io/mon-compte   # pousse aussi les 2 consommateurs
```

Prérequis : `docker login <registry>` fait au préalable.
Écraser le tag : `make push REGISTRY=... TAG=v1`.

---

## Détails d'implémentation

- **Producer** (`user-service/src/Messaging/KafkaProducer.php`) : `ext-rdkafka`,
  `enable.idempotence=true`, `flush()` bloquant → l'API renvoie 201 seulement
  si l'évènement est bien parti.
- **Consumers** (`*/src/Messaging/KafkaConsumer.php`) : consommateur haut niveau,
  `enable.auto.commit=false`, commit après succès, arrêt propre sur `SIGTERM`
  (`SignalableCommandInterface`).
- **Enveloppe d'évènement** :
  ```json
  { "eventType": "USER_CREATED", "occurredAt": "2026-09-07T12:00:00+00:00",
    "data": { "id": "...", "email": "...", "firstName": "...", "lastName": "...", "createdAt": "..." } }
  ```
- **Limite connue** : publication en double écriture (DB puis Kafka) sans
  transaction outbox — suffisant pour une démo locale, à durcir en prod.
- `composer.lock` n'est pas fourni ; le build Docker fait `composer install`
  puis `composer update` en secours. Pour figer : lancer `composer install`
  dans chaque service et committer les `composer.lock`.
