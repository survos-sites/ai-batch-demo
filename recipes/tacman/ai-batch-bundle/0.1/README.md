# Local Recipe Staging

This directory mirrors the Symfony Recipes Contrib structure for:

- `tacman/ai-batch-bundle`
- recipe version `0.1`

When ready to publish:

1. Copy this folder into `symfony/recipes-contrib` under the same path.
2. Adjust `manifest.json` as needed for final package constraints.
3. Open a PR in recipes-contrib.

Current recipe contents:

- bundle registration (`manifest.json`)
- route import for generic batch UI (`config/routes/tacman_ai_batch_ui.yaml`)
- `OPENAI_API_KEY` env placeholder
