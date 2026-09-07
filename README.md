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

| Élément                | Rôle                                             | Image (Docker Hub)                       |
|------------------------|--------------------------------------------------|-----------------------------------------|
| `user-service`         | API REST Symfony 7.3, publie `USER_CREATED`      | `ahceneaiti/php-api-user:latest`        |
| `postgres`             | Stockage des utilisateurs                        | `ahceneaiti/postgresql-user:latest`     |
| `kafka`                | Bus d'évènements (KRaft, sans ZooKeeper)         | `ahceneaiti/kafka-user:latest`          |
| `notification-service` | Consommateur → mail de bienvenue                 | `ahceneaiti/notification-service:latest`|
| `audit-service`        | Consommateur → journal d'audit                   | `ahceneaiti/audit-service:latest`       |
| `mailhog`              | SMTP de test + UI web                            | `mailhog/mailhog:v1.0.1`               |
| `kafka-ui`             | Interface web pour inspecter topics / messages   | `kafbat/kafka-ui:latest`               |

Les images applicatives sont publiées sur Docker Hub (`docker.io/ahceneaiti/*`).
Chaque service garde son `Dockerfile` pour rebuild local si besoin.

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

## Option A — docker compose

```bash
docker compose pull          # récupère les images ahceneaiti/* depuis Docker Hub
docker compose up -d         # (ou : docker compose up --build -d  pour rebuild local)

# API      : http://localhost:8080
# Kafka UI : http://localhost:8090
# MailHog  : http://localhost:8025

curl -s -X POST http://localhost:8080/api/users \
  -H 'Content-Type: application/json' \
  -d '{"email":"ada@example.com","firstName":"Ada","lastName":"Lovelace"}'

docker compose logs -f notification-service audit-service

docker compose down -v        # stoppe + supprime les volumes
```

---

## Option B — Kubernetes local sur kind

Déploiement **manuel pas à pas** entièrement documenté dans
**[`k8s/README.md`](k8s/README.md)** : création du cluster, `kubectl apply -k k8s`,
attente des rollouts, accès aux services, base de données, commandes Kafka,
5 exemples de publication de message, migrations, redéploiement, nettoyage,
dépannage.

Résumé :

```bash
kind create cluster --config kind-config.yaml
kubectl apply -k k8s
kubectl -n user-platform wait --for=condition=available --timeout=300s deployment --all

# API      : http://localhost:8080        (NodePort 30080)
# Kafka UI : http://localhost:8090        (NodePort 30808)
# MailHog  : http://localhost:8025        (NodePort 30825)

curl -s -X POST http://localhost:8080/api/users \
  -H 'Content-Type: application/json' \
  -d '{"email":"grace@example.com","firstName":"Grace","lastName":"Hopper"}'

kubectl -n user-platform logs -l app=notification-service -f
kubectl -n user-platform logs -l app=audit-service -f

kubectl delete -k k8s
kind delete cluster --name user-platform
```

Aucun build ni `kind load` : le cluster tire les images `ahceneaiti/*:latest`
depuis Docker Hub (`imagePullPolicy: Always`). Les migrations Doctrine tournent
dans un `initContainer` du Deployment `user-service`.

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
- `composer.lock` n'est pas commité ; le build Docker résout les dépendances
  avec `--no-security-blocking` (l'environnement de build bloque à tort toutes
  les versions Symfony). Pour figer : `composer install` dans chaque service
  puis committer les `composer.lock`.
- **Images** : publiées sur `docker.io/ahceneaiti/*`. Rebuild/repush manuel :
  `docker build -t ahceneaiti/<nom>:latest ./<service> && docker push ahceneaiti/<nom>:latest`.
