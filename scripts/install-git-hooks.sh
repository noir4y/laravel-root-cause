#!/bin/sh

set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$ROOT_DIR"

if ! command -v git >/dev/null 2>&1; then
    echo "Skipping git hook installation: git is not available."
    exit 0
fi

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "Skipping git hook installation: not inside a git worktree."
    exit 0
fi

if ! chmod +x .githooks/pre-commit; then
    echo "Skipping git hook installation: could not mark .githooks/pre-commit as executable."
    exit 0
fi

CURRENT_HOOKS_PATH=$(git config --local --get core.hooksPath 2>/dev/null || true)

if [ "$CURRENT_HOOKS_PATH" = ".githooks" ]; then
    echo "Repository git hooks already point to .githooks."
    exit 0
fi

if ! git config --local core.hooksPath .githooks; then
    echo "Skipping git hook installation: could not update .git/config."
    exit 0
fi

echo "Installed repository git hooks from .githooks."
