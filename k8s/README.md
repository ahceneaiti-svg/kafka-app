# Déploiement Kubernetes (kind) — pas à pas

Manifestes `kustomize` pour faire tourner toute la plateforme sur un cluster
Kubernetes local [kind](https://kind.sigs.k8s.io/), **sans build local** :
toutes les images sont tirées depuis Docker Hub.

## Sommaire

1. [Prérequis](#prérequis)
2. [Images utilisées](#images-utilisées)
3. [Contenu des manifestes](#contenu-des-manifestes)
4. [Déploiement pas à pas](#déploiement-pas-à-pas)
5. [Vérification](#vérification)
6. [Accès aux services](#accès-aux-services)
7. [Base de données PostgreSQL](#base-de-données-postgresql)
8. [Kafka](#kafka)
9. [Exemples de publication de message](#exemples-de-publication-de-message)
10. [Migrations Doctrine](#migrations-doctrine)
11. [Mise à jour / redéploiement](#mise-à-jour--redéploiement)
12. [Nettoyage](#nettoyage)
13. [Dépannage](#dépannage)

---

## Prérequis

| Outil     | Rôle                                    |
|-----------|-----------------------------------------|
| `docker`  | runtime du nœud kind                    |
| `kind`    | cluster Kubernetes local                |
| `kubectl` | pilotage du cluster                     |

Aucun build d'image n'est nécessaire : le cluster télécharge les images
publiques depuis Docker Hub.

---

## Images utilisées

| Composant              | Image                                   |
|------------------------|-----------------------------------------|
| user-service (API)     | `ahceneaiti/php-api-user:latest`        |
| PostgreSQL             | `ahceneaiti/postgresql-user:latest`     |
| Kafka                  | `ahceneaiti/kafka-user:latest`          |
| notification-service   | `ahceneaiti/notification-service:latest`|
| audit-service          | `ahceneaiti/audit-service:latest`       |
| Kafka UI               | `kafbat/kafka-ui:latest`                |
| MailHog                | `mailhog/mailhog:v1.0.1`                |

Toutes les images applicatives sont en `imagePullPolicy: Always` (tag `latest`) :
un `rollout restart` retélécharge la dernière version.

---

## Contenu des manifestes

| Fichier                        | Objets                                                              |
|--------------------------------|-------------------------------------------------------------------|
| `00-namespace.yaml`            | Namespace `user-platform`                                        |
| `05-config.yaml`               | `ConfigMap` app-config + `Secret` app-secrets                    |
| `10-postgres.yaml`             | Deployment + Service + `PersistentVolumeClaim` (1Gi)             |
| `20-kafka.yaml`                | Deployment + Service (Apache Kafka 3.8, KRaft, `emptyDir`)       |
| `25-kafka-ui.yaml`             | Deployment + Service `NodePort` 30808 (UI web)                   |
| `30-mailhog.yaml`              | Deployment + Service SMTP + Service `NodePort` 30825 (UI)        |
| `40-user-service.yaml`         | Deployment (+ `initContainer` migrations) + Service `NodePort` 30080 |
| `50-notification-service.yaml` | Deployment (consumer, groupe `notification-service`)             |
| `60-audit-service.yaml`        | Deployment (consumer, groupe `audit-service`)                    |
| `kustomization.yaml`           | agrège les manifestes ci-dessus                                  |

Tout est déployé dans le namespace **`user-platform`**.

---

## Déploiement pas à pas

### Étape 1 — Créer le cluster kind

Depuis la racine du dépôt (le fichier `kind-config.yaml` y est) :

```bash
kind create cluster --config kind-config.yaml
```

`kind-config.yaml` mappe trois `NodePort` vers l'hôte :

| Conteneur (NodePort) | Hôte             |
|----------------------|------------------|
| `30080`              | `localhost:8080` (API) |
| `30808`              | `localhost:8090` (Kafka UI) |
| `30825`              | `localhost:8025` (MailHog) |

Vérifier le contexte kubectl :

```bash
kubectl config current-context      # -> kind-user-platform
kubectl get nodes                   # -> user-platform-control-plane  Ready
```

### Étape 2 — Appliquer les manifestes

```bash
kubectl apply -k k8s
```

Sortie attendue :

```
namespace/user-platform created
configmap/app-config created
secret/app-secrets created
service/kafka created
service/kafka-ui created
service/mailhog created
service/mailhog-ui created
service/postgres created
service/user-service created
persistentvolumeclaim/postgres-data created
deployment.apps/audit-service created
deployment.apps/kafka created
deployment.apps/kafka-ui created
deployment.apps/mailhog created
deployment.apps/notification-service created
deployment.apps/postgres created
deployment.apps/user-service created
```

### Étape 3 — Attendre que tout soit prêt

```bash
kubectl -n user-platform wait --for=condition=available --timeout=300s deployment --all
```

Ou suivre un par un :

```bash
kubectl -n user-platform rollout status deploy/postgres
kubectl -n user-platform rollout status deploy/kafka
kubectl -n user-platform rollout status deploy/user-service      # migrations dans l'initContainer
kubectl -n user-platform rollout status deploy/notification-service
kubectl -n user-platform rollout status deploy/audit-service
kubectl -n user-platform rollout status deploy/kafka-ui
```

Ordre de démarrage : `postgres` + `kafka` d'abord ; `user-service` attend Postgres
(l'`initContainer` `migrate` échoue et est relancé tant que la base n'est pas prête) ;
les consumers réessaient la connexion Kafka en boucle jusqu'à ce que le broker réponde.

### Étape 4 — Sonde de santé

```bash
curl -s http://localhost:8080/health      # {"status":"ok"}
```

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

---

## Accès aux services

| Service            | URL / accès hôte                    | NodePort | Service interne          |
|--------------------|-------------------------------------|----------|--------------------------|
| user-service (API) | http://localhost:8080               | 30080    | `user-service:80` → 8000 |
| Kafka UI           | http://localhost:8090               | 30808    | `kafka-ui:8080`          |
| MailHog UI         | http://localhost:8025               | 30825    | `mailhog-ui:8025`        |
| PostgreSQL         | *(pas de NodePort)* → port-forward  | —        | `postgres:5432`          |
| Kafka              | *(pas de NodePort)* → port-forward  | —        | `kafka:9092`             |

### Cluster créé sans les mappings de port (cluster préexistant)

Script fourni : **`k8s/port-forward.sh`** ouvre les 3 tunnels d'un coup et les
ferme proprement au `Ctrl-C`.

```bash
./k8s/port-forward.sh
#   user-service   -> http://localhost:8080
#   kafka-ui       -> http://localhost:8090
#   mailhog-ui     -> http://localhost:8025
#   Ctrl-C pour tout arrêter.
```

Namespace surchargeable : `NAMESPACE=autre ./k8s/port-forward.sh`.

Équivalent manuel (un terminal par commande) :

```bash
kubectl -n user-platform port-forward svc/user-service 8080:80
kubectl -n user-platform port-forward svc/kafka-ui     8090:8080
kubectl -n user-platform port-forward svc/mailhog-ui   8025:8025
```

### DNS interne au cluster

Depuis un pod du namespace : `user-service`, `postgres:5432`, `kafka:9092`,
`mailhog:1025`. Depuis un autre namespace :
`<svc>.user-platform.svc.cluster.local`.

---

## Base de données PostgreSQL

Identifiants (depuis `05-config.yaml`) : base `app`, user `app`, mot de passe `app`.

### `psql` dans le pod

```bash
kubectl -n user-platform exec -it deploy/postgres -- psql -U app -d app
```

```sql
\dt
SELECT id, email, first_name, last_name, created_at
FROM users ORDER BY created_at DESC;

SELECT count(*) FROM users;

SELECT version FROM doctrine_migration_versions;   -- migrations appliquées
```

### Requête one-shot

```bash
kubectl -n user-platform exec -it deploy/postgres -- \
  psql -U app -d app -c 'SELECT email, created_at FROM users ORDER BY created_at DESC LIMIT 5;'
```

### Accès depuis l'hôte (DBeaver, psql local, …)

```bash
kubectl -n user-platform port-forward svc/postgres 5432:5432
# puis :
psql "postgresql://app:app@localhost:5432/app"
```

### PVC

```bash
kubectl -n user-platform get pvc postgres-data
```

> ⚠️ Postgres = PVC (données persistantes entre redéploiements).
> Kafka = `emptyDir` : les messages sont **perdus** si le pod `kafka` redémarre.

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

**Clé** du message = id de l'utilisateur. **Header** `eventType` présent.

Les scripts Kafka sont dans `/opt/kafka/bin/` du pod `kafka`.

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
# Ctrl-C pour quitter. Sans --from-beginning : uniquement les nouveaux messages.
```

### Offsets / lag des groupes consumers

```bash
kubectl -n user-platform exec deploy/kafka -- \
  /opt/kafka/bin/kafka-consumer-groups.sh --bootstrap-server localhost:9092 \
  --describe --all-groups
# groupes attendus : notification-service, audit-service  (LAG 0 = à jour)
```

### Via l'UI web

http://localhost:8090 → cluster `user-platform` → *Topics* → `user-events` →
*Messages* ; *Consumers* pour les groupes et le lag.

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

### 2. Publication manuelle dans le topic (test des consumers)

```bash
kubectl -n user-platform exec -i deploy/kafka -- \
  /opt/kafka/bin/kafka-console-producer.sh --bootstrap-server localhost:9092 \
  --topic user-events --property parse.key=true --property key.separator='|' <<'EOF'
11111111-1111-1111-1111-111111111111|{"eventType":"USER_CREATED","occurredAt":"2026-09-07T10:00:00+00:00","data":{"id":"11111111-1111-1111-1111-111111111111","email":"manual@example.com","firstName":"Manual","lastName":"Test","createdAt":"2026-09-07T10:00:00+00:00"}}
EOF
```

`notification-service` enverra un mail à `manual@example.com`,
`audit-service` écrira une ligne d'audit — visible dans leurs logs.

### 3. Producer interactif

```bash
kubectl -n user-platform exec -it deploy/kafka -- \
  /opt/kafka/bin/kafka-console-producer.sh --bootstrap-server localhost:9092 \
  --topic user-events
# une ligne JSON par message, Ctrl-D pour terminer :
> {"eventType":"USER_CREATED","occurredAt":"2026-09-07T10:05:00+00:00","data":{"id":"x","email":"iface@example.com","firstName":"I","lastName":"F","createdAt":"2026-09-07T10:05:00+00:00"}}
```

### 4. Depuis l'hôte avec `kcat`

```bash
kubectl -n user-platform port-forward svc/kafka 9092:9092 &

echo '{"eventType":"USER_CREATED","occurredAt":"2026-09-07T10:10:00+00:00","data":{"id":"y","email":"kcat@example.com","firstName":"K","lastName":"C","createdAt":"2026-09-07T10:10:00+00:00"}}' \
  | kcat -b localhost:9092 -t user-events -P -k "y"
```

### 5. Depuis la Kafka UI

http://localhost:8090 → *Topics* → `user-events` → bouton **Produce Message** :
*Key* = id, *Value* = le JSON de l'enveloppe, puis *Produce*.

> ℹ️ Seul `eventType == "USER_CREATED"` est traité par les consumers.
> Un autre `eventType` est lu puis ignoré (l'offset avance quand même).

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

Les images étant en `latest` + `imagePullPolicy: Always`, il suffit de
redémarrer le Deployment pour récupérer une nouvelle version publiée :

```bash
kubectl -n user-platform rollout restart deploy/user-service
kubectl -n user-platform rollout status  deploy/user-service
```

Après modification d'un manifeste :

```bash
kubectl apply -k k8s
```

Forcer le retéléchargement de toutes les images applicatives :

```bash
kubectl -n user-platform rollout restart deploy/user-service deploy/notification-service deploy/audit-service
```

---

## Nettoyage

```bash
kubectl delete -k k8s                        # supprime le namespace et tout son contenu
kind delete cluster --name user-platform     # supprime le cluster
```

---

## Dépannage

| Symptôme                                              | Piste                                                                 |
|------------------------------------------------------|----------------------------------------------------------------------|
| Pod `user-service` bloqué en `Init:*` / `Init:CrashLoopBackOff` | `kubectl -n user-platform logs deploy/user-service -c migrate` — Postgres pas encore prêt (se résout seul) |
| `ImagePullBackOff` / `ErrImagePull`                 | vérifier le nom d'image (`kubectl -n user-platform describe pod <pod>`), connectivité Docker Hub, quotas de pull |
| `http://localhost:8090` ne répond pas              | cluster créé avant l'ajout du mapping → `kubectl -n user-platform port-forward svc/kafka-ui 8090:8080` |
| Consumers : `brokers are down` au démarrage        | normal quelques secondes le temps que le pod `kafka` soit prêt (retry automatique) |
| `Broker: Unknown topic or partition`               | le topic est créé à la 1ʳᵉ publication ; ignorer au démarrage        |
| `curl localhost:8080` : `Connection refused`       | `kubectl -n user-platform get pod -l app=user-service` ; sinon `kubectl port-forward svc/user-service 8080:80` |
| Lag qui ne descend pas                              | `kubectl -n user-platform logs deploy/notification-service` pour l'erreur du handler (message non commité, rejoué) |
| Pas de mail dans MailHog                            | `MAILER_DSN=smtp://mailhog:1025` dans le ConfigMap `app-config` ?    |
