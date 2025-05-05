#!/bin/sh
[ "$#" = "0" ] && echo "usage: $0 {command}" >&2 && exit 1
exec docker compose run --rm --no-deps web scripts/docker_user_hack.sh "$@"
