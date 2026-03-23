#!/bin/bash
set -e

UNLINK=false
[[ "$1" == "--unlink" ]] && UNLINK=true

if [ "$UNLINK" = true ]; then
    composer remove tacman/ai-batch-bundle --dev --no-update --no-interaction 2>/dev/null || true
    composer config --unset repositories.ai-batch-bundle 2>/dev/null || true
    echo "Unlinked ai-batch-bundle"
else
    composer config repositories.ai-batch-bundle path ../../tacman/ai-batch-bundle
    composer require tacman/ai-batch-bundle:@dev --no-update --no-interaction
    echo "Linked ai-batch-bundle from ~/tacman/ai-batch-bundle"
fi

composer update tacman/ai-batch-bundle --no-interaction
