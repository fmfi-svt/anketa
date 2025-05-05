#!/bin/bash
set -e
rootdir=$(readlink -fv "$(dirname "$0")/..")
if [[ "$UID" == 0 && ! -O "$rootdir" ]]; then
  target_uid=$(stat -c %u "$rootdir")
  target_gid=$(stat -c %g "$rootdir")
  target_home=$rootdir/docker_cache
  if ! getent group "$target_gid" &> /dev/null; then
    groupadd user -g "$target_gid"
  fi
  if ! getent passwd "$target_uid" &> /dev/null; then
    home_opt=--create-home
    [[ -e "$target_home" ]] && home_opt=--no-create-home
    useradd user -u "$target_uid" -g "$target_gid" -d "$rootdir/docker_cache" "$home_opt" --no-log-init
  fi
  su user -c '"$@"' -- '' "$@"
else
  "$@"
fi
