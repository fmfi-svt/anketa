# Anketa

**Hlavná stránka: https://anketa.uniba.sk/**

Tento repozitár: https://github.com/fmfi-svt/anketa

Interná dokumentácia: https://github.com/fmfi-svt/wiki/tree/master/anketa

## Docker development setup

```sh
scripts/docker_init.sh

scp anketa.uniba.sk:/home/anketabackup/backups/anketa_fmph/latest-anonymous.sql.xz /tmp/
xzcat -v /tmp/latest-anonymous.sql.xz | docker compose exec -T db mysql -uanketa -panketa anketa
rm /tmp/latest-anonymous.sql.xz
```
