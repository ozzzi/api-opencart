# API for OpenCart (Laravel)

This repository provides a Laravel-based API layer for an existing OpenCart store. It exposes endpoints and search capabilities that integrate with your OpenCart database and optional search backends.

## 1. Prerequisites
- PHP 8.2+ with required extensions for Laravel
- Composer
- MySQL/MariaDB access to your OpenCart database
- Optional: Docker and Docker Compose (this repo includes Docker configs)

## 2. Clone the Repository

- git clone https://github.com/ozzzi/api-opencart.git

Then enter the project directory:
- cd api-opencart

## 3. Install Laravel Dependencies
Install PHP dependencies:
- composer install --no-dev --prefer-dist --optimize-autoloader

Copy the environment file and generate the app key:
- cp .env.example .env
- php artisan key:generate

## 4. Configure .env
Edit the .env file and set the following variables.

1) OpenCart database connection
- DB_HOST=127.0.0.1
- DB_PORT=3306
- DB_DATABASE=            # OpenCart database name
- DB_USERNAME=            # database username
- DB_PASSWORD=            # database password

2) API access
- API_TOKEN=              # Authentication token required by the API
- API_IP_ADDRESS=         # IP address allowed to call the API (single IP)

3) Search configuration

- SEARCH_API_KEY=         # Access token for your search backend (e.g., OpenSearch token you set during install)
- SEARCH_USER=            # Search backend user (if applicable)
- SEARCH_HOST=            # Host of the search service
- SEARCH_PORT=            # Port of the search service
- SEARCH_SSL=true|false   # Whether to use HTTPS/TLS when connecting
- EMBEDDED_URL=           # Embedding API URL, default is http://localhost:8000/vectorize
- SEARCH_DISTANCE_THRESHOLD=  # Relevance score threshold; documents below this are filtered out
- SEARCH_DEBUG=true/false # Enable verbose debug output for search
