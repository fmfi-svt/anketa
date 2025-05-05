#!/bin/bash
# Runs a command inside Docker with the UID/GID of the user outside Docker. That
# way, vendor/, config_local.yml etc. will be owned by the normal user instead
# of root:root, matching our production setup. Started by docker_run.sh.
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
