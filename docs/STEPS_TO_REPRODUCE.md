# Steps to Reproduce (From Scratch)

## 1) Create app

```bash
symfony new --webapp postcard-ai-demo
cd postcard-ai-demo
```

## 2) Point Composer to Symfony AI fork

```bash
composer config repositories.symfony-ai vcs https://github.com/camilleislasse/ai
```

Require forked Symfony AI + dependencies used in demo:

```bash
composer require \
  symfony/ai:"dev-feature/batch-processing as 1.0" \
  symfony/object-mapper:^8.1 \
  mezcalito/ux-search \
  survos/jsonl-bundle
```

Set minimum stability to allow 8.1 dev experimentation (optional but used in this demo):

```bash
composer config minimum-stability dev
composer config prefer-stable true
```

## 3) Add Tacman bundle (local dev)

```bash
composer config repositories.tacman-ai-batch-bundle path ../../tacman/ai-batch-bundle
composer require tacman/ai-batch-bundle:@dev
```

Register bundle in `config/bundles.php`:

- `Tacman\AiBatch\TacmanAiBatchBundle::class => ['all' => true]`

## 4) Configure env

In `.env`:

```dotenv
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db"
OPENAI_API_KEY=
MEZCALITO_UX_SEARCH_DEFAULT_DSN=doctrine://default
```

In `.env.local`:

```dotenv
OPENAI_API_KEY=sk-...
```

## 5) Add demo code files

Copy/create the small set of files from this demo:

- `src/Entity/Postcard.php`
- `src/Entity/PostcardKeyword.php`
- `src/AiTask/PostcardEnrichmentOutput.php`
- `src/AiTask/EnrichPostcardAiTask.php`
- `src/Message/EnrichPostcardMessage.php`
- `src/Command/LoadPostcardsCommand.php`
- `src/Command/EnrichPostcardsCommand.php`
- `src/Search/PostcardSearch.php`
- `src/Controller/HomeController.php`
- `templates/home/index.html.twig`
- `templates/home/batch_show.html.twig`
- `templates/bundles/MezcalitoUxSearchBundle/Hits.html.twig`

Also ensure UX Search + importmap/controller config is present:

- `config/packages/mezcalito_ux_search.yaml`
- `assets/controllers.json`
- `importmap.php`

## 6) Prepare sample postcard data

Reference command used for this demo:

```bash
head -n 100 /media/tac/WD-001/mus/data/dc/0p096w19r/20_normalize/obj.jsonl > data/postcards.jsonl
```

## 7) Build schema + load data

```bash
php bin/console doctrine:schema:update --force
php bin/console app:load:postcards --reset
```

## 8) Run sync enrichment (small first)

```bash
php bin/console app:enrich:postcards --mode=sync --limit=5 --force -vv
```

## 9) First larger sync run

```bash
php bin/console app:enrich:postcards --mode=sync --limit=20
```

## 10) Open UI

```bash
symfony serve -d
```

Visit:

- `https://127.0.0.1:8000/`
- `https://127.0.0.1:8000/_ai/batches`

Inspect:

- postcard search
- keyword/country/city facets
- batch widgets and detail links

## 11) Batch run (next)

```bash
php bin/console app:enrich:postcards --mode=batch --limit=20
```

Then inspect batch state in the homepage and `/batch/{id}` pages.
