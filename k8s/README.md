# Déploiement Kubernetes (kind)

Manifestes `kustomize` pour faire tourner toute la plateforme sur un cluster
Kubernetes local [kind](https://kind.sigs.k8s.io/).

## Sommaire

1. [Prérequis](#prérequis)
2. [Contenu des manifestes](#contenu-des-manifestes)
3. [Déploiement](#déploiement)
4. [Vérification](#vérification)
5. [Accès aux services](#accès-aux-services)
6. [Base de données PostgreSQL](#base-de-données-postgresql)
7. [Kafka](#kafka)
8. [Exemples de publication de message](#exemples-de-publication-de-message)
9. [Migrations Doctrine](#migrations-doctrine)
10. [Mise à jour / redéploiement](#mise-à-jour--redéploiement)
11. [Nettoyage](#nettoyage)
12. [Dépannage](#dépannage)

---

## Prérequis

| Outil     | Rôle                              |
|-----------|-----------------------------------|
| `docker`  | build des images + runtime kind   |
| `kind`    | cluster Kubernetes local          |
| `kubectl` | pilotage du cluster               |

Les images applicatives (`php-api-user`, `notification-service`, `audit-service`)
sont **construites en local** et injectées dans le cluster avec `kind load`
(`imagePullPolicy: IfNotPresent`, aucun registry requis).

---

## Contenu des manifestes

| Fichier                     | Objets                                                              |
|-----------------------------|-------------------------------------------------------------------|
| `00-namespace.yaml`         | Namespace `user-platform`                                        |
| `05-config.yaml`            | `ConfigMap` app-config + `Secret` app-secrets                    |
| `10-postgres.yaml`          | Deployment + Service + `PersistentVolumeClaim` (1Gi)             |
| `20-kafka.yaml`             | Deployment + Service (Apache Kafka 3.8, KRaft, `emptyDir`)       |
| `25-kafka-ui.yaml`          | Deployment + Service `NodePort` 30808 (UI web)                   |
| `30-mailhog.yaml`           | Deployment + Service SMTP + Service `NodePort` 30825 (UI)        |
| `40-user-service.yaml`      | Deployment (+ `initContainer` migrations) + Service `NodePort` 30080 |
| `50-notification-service.yaml` | Deployment (consumer, groupe `notification-service`)          |
| `60-audit-service.yaml`     | Deployment (consumer, groupe `audit-service`)                    |
| `kustomization.yaml`        | agrège les manifestes ci-dessus                                  |

Tout est déployé dans le namespace **`user-platform`**.

---

## Déploiement

### Option 1 — Makefile (depuis la racine du dépôt)

```bash
make kind-up     # crée le cluster "user-platform" (mappe 8080, 8090, 8025 vers l'hôte)
make deploy      # build images -> kind load -> kubectl apply -k k8s -> attend le rollout
make k8s-status  # kubectl -n user-platform get pods,svc
```

### Option 2 — étapes manuelles

```bash
# 1. cluster
kind create cluster --config kind-config.yaml

# 2. build des 3 images applicatives
docker build -t php-api-user:local        ./user-service
docker build -t notification-service:local ./notification-service
docker build -t audit-service:local       ./audit-service

# 3. injection dans le cluster kind
kind load docker-image php-api-user:local notification-service:local audit-service:local \
  --name user-platform

# 4. application des manifestes
kubectl apply -k k8s

# 5. attente
kubectl -n user-platform rollout status deploy/user-service --timeout=240s
```

> Les images `postgres:16-alpine`, `apache/kafka:3.8.0`, `kafbat/kafka-ui`,
> `mailhog/mailhog` sont tirées depuis Docker Hub par le cluster.

---

## Vérification

```bash
kubectl -n user-platform get pods
# NAME                                    READY   STATUS    RESTARTS   AGE
# audit-service-...                       1/1     Running   0          2m
# kafka-...                               1/1     Running   0          2m
# kafka-ui-...                            1/1     Running   0          2m
# mailhog-...                             1/1     Running   0          2m
# notification-service-...               1/1     Running   0          2m
# postgres-...                            1/1     Running   0          2m
# user-service-...                       1/1     Running   0          2m

kubectl -n user-platform get svc
kubectl -n user-platform logs -l app=user-service -f
kubectl -n user-platform logs -l app=notification-service -f
kubectl -n user-platform logs -l app=audit-service -f
```

Sonde de santé de l'API :

```bash
curl -s http://localhost:8080/health      # {"status":"ok"}
```

---

## Accès aux services

Le fichier `kind-config.yaml` mappe les `NodePort` vers `localhost` :

| Service    | URL / accès hôte                | NodePort | Service ClusterIP interne |
|------------|---------------------------------|----------|---------------------------|
| user-service (API) | http://localhost:8080   | 30080    | `user-service:80` → 8000  |
| Kafka UI   | http://localhost:8090           | 30808    | `kafka-ui:8080`           |
| MailHog UI | http://localhost:8025           | 30825    | `mailhog-ui:8025`         |
| PostgreSQL | *(pas de NodePort)* → port-forward | —     | `postgres:5432`           |
| Kafka      | *(pas de NodePort)* → port-forward | —     | `kafka:9092`              |

### Si le cluster a été créé sans les mappings (ex. cluster préexistant)

```bash
kubectl -n user-platform port-forward svc/user-service 8080:80
kubectl -n user-platform port-forward svc/kafka-ui     8090:8080
kubectl -n user-platform port-forward svc/mailhog-ui   8025:8025
```

### DNS interne au cluster

Depuis un pod du namespace : `user-service`, `postgres:5432`, `kafka:9092`,
`mailhog:1025`. Depuis un autre namespace : `<svc>.user-platform.svc.cluster.local`.

---

## Base de données PostgreSQL

Identifiants (depuis `05-config.yaml`) : base `app`, user `app`, mot de passe `app`.

### `psql` dans le pod

```bash
kubectl -n user-platform exec -it deploy/postgres -- psql -U app -d app

# exemples de requêtes
\dt
SELECT id, email, first_name, last_name, created_at FROM users ORDER BY created_at DESC;
SELECT count(*) FROM users;
SELECT version() FROM doctrine_migration_versions;   -- migrations appliquées
```

Requête one-shot :

```bash
kubectl -n user-platform exec -it deploy/postgres -- \
  psql -U app -d app -c 'SELECT email, created_at FROM users ORDER BY created_at DESC LIMIT 5;'
```

### Accès depuis l'hôte (DBeaver, psql local, …)

```bash
kubectl -n user-platform port-forward svc/postgres 5432:5432
# puis : psql "postgresql://app:app@localhost:5432/app"
```

### Inspecter la PVC

```bash
kubectl -n user-platform get pvc postgres-data
```

> ⚠️ Postgres utilise une PVC (données persistantes entre redéploiements).
> Kafka utilise un `emptyDir` (les messages sont **perdus** si le pod kafka redémarre).

---

## Kafka

Topic applicatif : **`user-events`** (créé automatiquement, 3 partitions).
Enveloppe d'un message :

```json
{
  "eventType": "USER_CREATED",
  "occurredAt": "2026-09-07T09:02:56+00:00",
  "data": {
    "id": "305a2792-ab37-49cc-b60b-ca0b8febe7c7",
    "email": "ada@example.com",
    "firstName": "Ada",
    "lastName": "Lovelace",
    "createdAt": "2026-09-07T09:02:56+00:00"
  }
}
```

La **clé** du message = id de l'utilisateur. Un **header** `eventType` est présent.

### Lister les topics

```bash
kubectl -n user-platform exec deploy/kafka -- \
  /opt/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --list
```

### Décrire le topic

```bash
kubectl -n user-platform exec deploy/kafka -- \
  /opt/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 \
  --describe --topic user-events
```

### Lire tous les messages (depuis le début)

```bash
kubectl -n user-platform exec -it deploy/kafka -- \
  /opt/kafka/bin/kafka-console-consumer.sh --bootstrap-server localhost:9092 \
  --topic user-events --from-beginning \
  --property print.key=true --property print.headers=true
# Ctrl-C pour quitter ; sans --from-beginning => uniquement les nouveaux messages
```

### Offsets / lag des groupes consumers

```bash
kubectl -n user-platform exec deploy/kafka -- \
  /opt/kafka/bin/kafka-consumer-groups.sh --bootstrap-server localhost:9092 \
  --describe --all-groups
# groupes attendus : notification-service, audit-service  (LAG 0 quand ils sont à jour)
```

### Via l'UI web

http://localhost:8090 → cluster `user-platform` → *Topics* → `user-events` → *Messages*.
*Consumers* pour les groupes et le lag.

---

## Exemples de publication de message

### 1. Voie normale — via l'API (déclenche `USER_CREATED`)

```bash
curl -s -X POST http://localhost:8080/api/users \
  -H 'Content-Type: application/json' \
  -d '{"email":"ada@example.com","firstName":"Ada","lastName":"Lovelace"}'
```

Effets : ligne en base + message `USER_CREATED` publié sur `user-events` +
`notification-service` (mail) et `audit-service` (audit) réagissent.

Vérifier la propagation :

```bash
kubectl -n user-platform logs -l app=notification-service --tail=5   # "Welcome email sent"
kubectl -n user-platform logs -l app=audit-service        --tail=5   # "AUDIT {...}"
curl -s http://localhost:8025/api/v2/messages | head -c 400          # MailHog
```

### 2. Publication manuelle dans le topic (test consumers)

```bash
kubectl -n user-platform exec -it deploy/kafka -- sh -c '
echo "305a2792-...:{\"eventType\":\"USER_CREATED\",\"occurredAt\":\"2026-09-07T10:00:00+00:00\",\"data\":{\"id\":\"305a2792-...\",\"email\":\"manual@example.com\",\"firstName\":\"Manual\",\"lastName\":\"Test\",\"createdAt\":\"2026-09-07T10:00:00+00:00\"}}" | \
/opt/kafka/bin/kafka-console-producer.sh --bootstrap-server localhost:9092 \
  --topic user-events --property parse.key=true --property key.separator=:'
```

`notification-service` tentera d'envoyer un mail à `manual@example.com`,
`audit-service` écrira une ligne d'audit.

### 3. Producer interactif

```bash
kubectl -n user-platform exec -it deploy/kafka -- \
  /opt/kafka/bin/kafka-console-producer.sh --bootstrap-server localhost:9092 \
  --topic user-events
# taper une ligne JSON par message, Ctrl-D pour terminer
> {"eventType":"USER_CREATED","occurredAt":"2026-09-07T10:05:00+00:00","data":{"id":"x","email":"iface@example.com","firstName":"I","lastName":"F","createdAt":"2026-09-07T10:05:00+00:00"}}
```

### 4. Depuis l'hôte avec `kcat`

```bash
kubectl -n user-platform port-forward svc/kafka 9092:9092 &

echo '{"eventType":"USER_CREATED","occurredAt":"2026-09-07T10:10:00+00:00","data":{"id":"y","email":"kcat@example.com","firstName":"K","lastName":"C","createdAt":"2026-09-07T10:10:00+00:00"}}' \
  | kcat -b localhost:9092 -t user-events -P -k "y"
```

### 5. Depuis la Kafka UI

http://localhost:8090 → *Topics* → `user-events` → **Produce Message** :
renseigner *Key* (id) et *Value* (le JSON de l'enveloppe), puis *Produce*.

---

## Migrations Doctrine

Elles tournent automatiquement dans l'`initContainer` `migrate` du Deployment
`user-service`, avant chaque démarrage de pod
(`php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing`).

Lancer manuellement :

```bash
kubectl -n user-platform exec -it deploy/user-service -- \
  php bin/console doctrine:migrations:status

kubectl -n user-platform exec -it deploy/user-service -- \
  php bin/console doctrine:migrations:migrate --no-interaction
```

---

## Mise à jour / redéploiement

Après modification du code d'un service :

```bash
docker build -t php-api-user:local ./user-service
kind load docker-image php-api-user:local --name user-platform
kubectl -n user-platform rollout restart deploy/user-service
kubectl -n user-platform rollout status  deploy/user-service
```

Après modification d'un manifeste :

```bash
kubectl apply -k k8s
```

(ou simplement `make deploy` qui refait build + load + apply.)

---

## Nettoyage

```bash
kubectl delete -k k8s            # supprime le namespace et tout son contenu
# ou : make undeploy

kind delete cluster --name user-platform
# ou : make kind-down
```

---

## Dépannage

| Symptôme                                              | Piste                                                                 |
|------------------------------------------------------|----------------------------------------------------------------------|
| Pod `user-service` en `Init:*`                       | `kubectl -n user-platform logs deploy/user-service -c migrate` (Postgres pas prêt ?) |
| `ErrImageNeverPull` / `ImagePullBackOff` (image `:local`) | `kind load docker-image <img>:local --name user-platform` oublié     |
| `http://localhost:8090` ne répond pas               | cluster créé avant l'ajout du mapping → `kubectl -n user-platform port-forward svc/kafka-ui 8090:8080` |
| Consumers : `brokers are down` au démarrage         | normal quelques secondes le temps que le pod `kafka` soit prêt (retry auto) |
| `Broker: Unknown topic or partition`                | le topic est créé à la 1ʳᵉ publication ; ignorer au démarrage        |
| Lag qui ne descend pas                               | `kubectl -n user-platform logs deploy/notification-service` pour l'erreur du handler |
| Pas de mail dans MailHog                             | vérifier `MAILER_DSN=smtp://mailhog:1025` dans le ConfigMap `app-config` |
