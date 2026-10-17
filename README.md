# Groupe Zidane — E-Commerce Core API

Plateforme backend modulaire e-commerce conçue en architecture **CQRS**, avec **Symfony 8.1**, **PHP 8.4**, **PostgreSQL 16**, **Meilisearch**, **Rate Limiting** et **Swagger UI**.

---

## 🚀 Démarrage Rapide

### 1. Prérequis
- **PHP** : >= 8.4
- **Composer** : >= 2.7
- **Base de données** : PostgreSQL 16
- **Moteur de recherche** : Meilisearch (port 7700)

### 2. Installation & Migrations

```bash
# Installation des dépendances
composer install

# Exécution des migrations de base de données
php bin/console doctrine:migrations:migrate -n
php bin/console doctrine:migrations:migrate -n --env=test

# Lancement du serveur de développement local
php -S 127.0.0.1:8000 -t public
```

---

## 📖 Documentation Interactive Swagger UI (OpenAPI 3.0)

Une fois le serveur démarré sur le port `8000` :

- **Swagger UI** : [http://localhost:8000/api/doc](http://localhost:8000/api/doc)
- **Raccourci** : [http://localhost:8000/docs](http://localhost:8000/docs)
- **Spécification OpenAPI JSON** : [http://localhost:8000/api/doc.json](http://localhost:8000/api/doc.json)

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

## 🧪 Tests & Qualité de Code

```bash
# Lancement de tous les tests unitaires et fonctionnels (100% verts)
vendor/bin/phpunit

# Analyse statique PHPStan (Level max - 0 erreur)
vendor/bin/phpstan analyse

# Vérification du style de code
vendor/bin/php-cs-fixer fix --dry-run --diff
```
