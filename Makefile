CLUSTER ?= user-platform
REGISTRY ?=
TAG ?= local

# image name -> build context
API_IMG   := php-api-user
PG_IMG    := postgresql-user
KAFKA_IMG := kafka-user
NOTIF_IMG := notification-service
AUDIT_IMG := audit-service

LOCAL_IMAGES := $(API_IMG) $(PG_IMG) $(KAFKA_IMG) $(NOTIF_IMG) $(AUDIT_IMG)

.PHONY: help
help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

## ---------- docker compose ----------

.PHONY: up down logs
up: ## Build + start the whole stack with docker compose
	docker compose up --build -d

down: ## Stop the stack and drop volumes
	docker compose down -v

logs: ## Tail all compose logs
	docker compose logs -f

## ---------- image build ----------

.PHONY: build
build: ## Build the 5 images as <name>:$(TAG)
	docker build -t $(API_IMG):$(TAG)   ./user-service
	docker build -t $(PG_IMG):$(TAG)    ./postgres
	docker build -t $(KAFKA_IMG):$(TAG) ./kafka
	docker build -t $(NOTIF_IMG):$(TAG) ./notification-service
	docker build -t $(AUDIT_IMG):$(TAG) ./audit-service

.PHONY: push
push: build ## Tag + push php-api-user, postgresql-user, kafka-user to $(REGISTRY)
	@test -n "$(REGISTRY)" || { echo "Set REGISTRY, e.g. make push REGISTRY=docker.io/youruser"; exit 1; }
	docker tag  $(API_IMG):$(TAG)   $(REGISTRY)/$(API_IMG):$(TAG)
	docker tag  $(PG_IMG):$(TAG)    $(REGISTRY)/$(PG_IMG):$(TAG)
	docker tag  $(KAFKA_IMG):$(TAG) $(REGISTRY)/$(KAFKA_IMG):$(TAG)
	docker push $(REGISTRY)/$(API_IMG):$(TAG)
	docker push $(REGISTRY)/$(PG_IMG):$(TAG)
	docker push $(REGISTRY)/$(KAFKA_IMG):$(TAG)

.PHONY: push-all
push-all: build ## Push all 5 images to $(REGISTRY)
	@test -n "$(REGISTRY)" || { echo "Set REGISTRY"; exit 1; }
	@for img in $(LOCAL_IMAGES); do \
		docker tag  $$img:$(TAG) $(REGISTRY)/$$img:$(TAG); \
		docker push $(REGISTRY)/$$img:$(TAG); \
	done

## ---------- kind / kubernetes ----------

.PHONY: kind-up kind-down kind-load deploy undeploy k8s-status
kind-up: ## Create the kind cluster
	kind create cluster --config kind-config.yaml

kind-down: ## Delete the kind cluster
	kind delete cluster --name $(CLUSTER)

kind-load: build ## Load locally built images into the kind cluster
	@for img in $(LOCAL_IMAGES); do kind load docker-image $$img:$(TAG) --name $(CLUSTER); done

deploy: kind-load ## Load images + apply all k8s manifests
	kubectl apply -k k8s
	kubectl -n user-platform rollout status deploy/user-service --timeout=240s

undeploy: ## Remove the k8s resources
	kubectl delete -k k8s --ignore-not-found

k8s-status: ## Show pods and services
	kubectl -n user-platform get pods,svc
