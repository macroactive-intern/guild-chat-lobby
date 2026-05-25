# Guild Chat Lobby

API-only Laravel 12 backend with Sanctum authentication.

## Stack

- Laravel 12
- Sanctum API token authentication
- SQLite by default
- MySQL supported through environment configuration

## Setup

Install dependencies:

```bash
composer install
```

Create the local environment file and app key:

```bash
cp .env.example .env
php artisan key:generate
```

For SQLite, create the database file:

```bash
touch database/database.sqlite
```

For MySQL, update these values in `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=guild_chat_lobby
DB_USERNAME=root
DB_PASSWORD=
```

Run migrations:

```bash
php artisan migrate
```

Start the API server:

```bash
php artisan serve
```

The API will be available at `http://localhost:8000`.

## API

The starter API route is protected with Sanctum:

```http
GET /api/user
Authorization: Bearer <token>
```
