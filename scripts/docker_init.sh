#!/bin/bash

set -e

verbosely () {
  printf '\e[33;1m[docker_init.sh] %s\e[0m\n' "$*"
  "$@"
}

verbosely cd "$(dirname "$0")/.."

verbosely docker compose build

verbosely scripts/docker_run.sh scripts/init_all.sh --www-user=www-data --skip-advice --mysql=db,anketa,anketa,anketa

verbosely docker compose up -d
