#!/usr/bin/env bash
#
# Ouvre les port-forwards vers les services du namespace user-platform.
# Utile quand le cluster kind a été créé sans les extraPortMappings de
# kind-config.yaml (NodePort non exposés sur l'hôte).
#
#   ./k8s/port-forward.sh
#
# Ctrl-C ferme proprement tous les tunnels.

set -euo pipefail

NAMESPACE="${NAMESPACE:-user-platform}"

# service:port_local:port_service
FORWARDS=(
  "user-service:8080:80"
  "kafka-ui:8090:8080"
  "mailhog-ui:8025:8025"
)

pids=()

cleanup() {
  echo
  echo "Fermeture des port-forwards..."
  for pid in "${pids[@]}"; do
    kill "$pid" 2>/dev/null || true
  done
  wait 2>/dev/null || true
  exit 0
}
trap cleanup INT TERM

if ! kubectl get namespace "$NAMESPACE" >/dev/null 2>&1; then
  echo "Namespace '$NAMESPACE' introuvable. Déployer d'abord : kubectl apply -k k8s" >&2
  exit 1
fi

echo "Port-forwards (namespace: $NAMESPACE)"
for entry in "${FORWARDS[@]}"; do
  svc="${entry%%:*}"
  rest="${entry#*:}"
  local_port="${rest%%:*}"
  svc_port="${rest#*:}"

  kubectl -n "$NAMESPACE" port-forward "svc/${svc}" "${local_port}:${svc_port}" \
    >/dev/null 2>&1 &
  pids+=("$!")
  printf '  %-14s -> http://localhost:%s\n' "$svc" "$local_port"
done

echo
echo "  API      : http://localhost:8080"
echo "  Kafka UI : http://localhost:8090"
echo "  MailHog  : http://localhost:8025"
echo
echo "Ctrl-C pour tout arrêter."

wait
