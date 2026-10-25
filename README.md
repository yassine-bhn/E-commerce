# Groupe Zidane — E-Commerce Core API

Plateforme backend modulaire e-commerce conçue en architecture **CQRS**, avec **Symfony 8.1**, **PHP 8.5**, **PostgreSQL 16**, **Meilisearch**, **Rate Limiting** et **Swagger UI**.

---

## 🚀 Démarrage Rapide

### 1. Prérequis
- **Docker Engine** + **Docker Compose v2** (INF-002 : aucun PHP, Composer ou `vendor/bin` sur l'hôte)
- **Make**

### 2. Démarrage

```bash
make up            # construit l'image dev et démarre app, worker, outbox, database, meilisearch
make migrate       # applique les migrations à la base de dev
make migrate-test  # applique les migrations à la base de test
make urls          # API, Swagger UI, OpenAPI JSON, Meilisearch
```

L'API répond sur `http://localhost:8081` (surchargeable via `APP_PORT` dans `.env`).

### 3. Opérations courantes

| Commande | Effet |
|---|---|
| `make help` | liste des cibles disponibles |
| `make ps` / `make logs` / `make logs ARGS="worker outbox"` | état et journaux des services |
| `make sh` | shell dans le conteneur `app` |
| `make composer ARGS="…"` / `make console ARGS="…"` | Composer / `bin/console` dans `app` |
| `make down` | arrêt de la pile (volumes nommés conservés) |
| `make db-backup` / `make db-restore ARGS="backups/db-<horodatage>.dump --yes"` | sauvegarde / restauration (PostgreSQL + uploads) |

Les services `worker` (transports `async` et `failed`) et `outbox` (`app:outbox:relay` toutes les 5 secondes) traitent les événements en local. `/healthz` (sans dépendance) et `/readyz` (base + migrations) exposent l'état du service.

---

## 📖 Documentation Interactive Swagger UI (OpenAPI 3.0)

Une fois la pile démarrée (`make up`) :

- **Swagger UI** : [http://localhost:8081/api/doc](http://localhost:8081/api/doc)
- **Raccourci** : [http://localhost:8081/docs](http://localhost:8081/docs)
- **Spécification OpenAPI JSON** : [http://localhost:8081/api/doc.json](http://localhost:8081/api/doc.json)

---

## 🛠️ Architecture & Périmètre Métier

Le projet est structuré selon les principes DDD / CQRS en 8 contextes délimités (*Bounded Contexts*) :

1. **Catalog** : Gestion du catalogue, multi-variantes, recherche Meilisearch, avis clients, validation anti-cycles de catégories.
2. **Cart** : Calcul dynamique du panier, devis, politiques de livraison et codes promo (`ZIDEN10`, `BIENVENUE`).
3. **Ordering** :
   - Prise de commande client Storefront.
   - **Commandes proxy Téléphone / WhatsApp** par l'administration avec génération d'instantané de prix et verrouillage de stock.
   - **Verrouillage Optimiste (OCC)** des statuts de commande avec suivi d'audit strict.
4. **Identity** : Profils clients, carnet d'adresses, demandes de devis, messages de contact, souscriptions newsletter et gouvernance de bannissement/anonymisation.
5. **Security & Protection** :
   - Système de **Rate Limiting** adaptatif (Commandes, Contacts, Recherche).
   - Journalisation d'audit des actions administratives.

---

## 📞 Contact WhatsApp (Storefront)

`GET /api/v1/storefront/contact/whatsapp` — lecture publique (sans authentification ni rate limiting)
qui renvoie le contact WhatsApp Business configuré et le lien de conversation prêt à ouvrir :

```json
{
  "phoneNumber": "21600000000",
  "message": "Hello, I would like more information about your products.",
  "chatUrl": "https://wa.me/21600000000?text=Hello%2C%20I%20would%20like%20more%20information%20about%20your%20products."
}
```

- `?message=…` remplace le message par défaut : 1 à 200 caractères Unicode, sans caractère de
  contrôle. Une valeur absente, vide ou composée d'espaces ASCII retombe sur le message configuré
  (`200`) ; une valeur trop longue, un retour à la ligne ou un tableau (`?message[]=…`) est refusé en
  `400` avec `{"error": "…"}`.
- La destination n'est jamais pilotée par la requête : le numéro, le schéma et l'hôte
  (`https://wa.me/`) viennent uniquement de la configuration.
- Variables : `WHATSAPP_PHONE_NUMBER` (chiffres uniquement, format international ; un `+` initial est
  accepté et retiré) et `WHATSAPP_DEFAULT_MESSAGE`, déclarées dans `.env` / `.env.test` et
  documentées dans `.env.prod.example`. En production, remplacez le placeholder `21600000000`.
- Contrat complet, cas d'erreur et garanties : [docs/whatsapp-contact-endpoint.md](docs/whatsapp-contact-endpoint.md).

---

## 🧪 Tests & Qualité de Code

```bash
make test              # les six suites PHPUnit (Unit, Integration, Projection, Functional, Contract, Architecture)
make test-unit         # une seule suite : test-integration, test-projection, test-functional, test-contract, test-architecture

make cs                # style (php-cs-fixer --dry-run) ; make cs-fix pour appliquer
make phpstan           # analyse statique (level 8)
make deptrac           # frontières entre contextes
make infection         # mutation testing (nécessite un driver de couverture)
make harness           # intégrité du harness .agents
make ci                # même séquence que le pipeline CI
```
