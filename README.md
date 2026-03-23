# Postcard AI Batch Demo

Small Symfony demo that:

- loads postcard metadata from JSONL
- enriches postcards with AI description + keywords
- persists batch jobs/results (Tacman bundle)
- provides a simple UI with search + batch visibility

## Requirements

- PHP 8.4+
- Composer
- SQLite (default in `.env`)
- OpenAI API key

## Install

```bash
composer install
```

Set your API key in `.env.local`:

```dotenv
OPENAI_API_KEY=sk-...
```

Create/update database schema:

```bash
php bin/console doctrine:schema:update --force
```

## Data Prep

Reference command used to extract a small sample file for this demo:

```bash
head -n 100 /media/tac/WD-001/mus/data/dc/0p096w19r/20_normalize/obj.jsonl > data/postcards.jsonl
```

Load postcards:

```bash
php bin/console app:load:postcards --reset
```

## Run the Demo

Start Symfony server:

```bash
symfony serve -d
```

Open:

- `https://127.0.0.1:8000/`
- `https://127.0.0.1:8000/_ai/batches` (generic bundle batch UI)

### Sync example (small)

Run a small sync enrichment first so the output is easy to inspect:

```bash
php bin/console app:enrich:postcards --mode=sync --limit=5 --force -vv
```

### First larger sync run

```bash
php bin/console app:enrich:postcards --mode=sync --limit=20
```

Then review search facets and enriched postcard cards on the homepage.

### Batch run (next step)

```bash
php bin/console app:enrich:postcards --mode=batch --limit=20
```

Use the batch UI on `/` and batch detail pages (`/batch/{id}`) to inspect progress/results.

Generic Tacman batch UI routes:

- `/_ai/batches`
- `/_ai/batches/{id}`

## Recipe Staging

Local recipe staging files live at:

- `recipes/tacman/ai-batch-bundle/0.1`

These are structured to be copied into `symfony/recipes-contrib` when ready.

## Key Files

Core demo logic is intentionally small:

- `src/Entity/Postcard.php`
- `src/Entity/PostcardKeyword.php`
- `src/AiTask/EnrichPostcardAiTask.php`
- `src/Command/LoadPostcardsCommand.php`
- `src/Command/EnrichPostcardsCommand.php`
- `src/Search/PostcardSearch.php`
- `src/Controller/HomeController.php`
- `templates/home/index.html.twig`
- `templates/home/batch_show.html.twig`
- `templates/bundles/MezcalitoUxSearchBundle/Hits.html.twig`
