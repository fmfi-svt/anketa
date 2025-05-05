#!/bin/bash

set -e

cd "$(dirname "$0")/.."
bold=$'\e[37;40;1m'
normal=$'\e[0m'

me=$(whoami)

skipadvice=
mysqlopt=
wwwuser=
for arg; do
  case "$arg" in
    --skip-advice) skipadvice=y;;
    --mysql=*,*,*,*) mysqlopt=${arg#*=};;
    --www-user=*) wwwuser=${arg#*=};;
    --help) echo >&2 "usage: $0 --www-user=www-data [--skip-advice] [--mysql=HOST,DBNAME,USER,PASSWORD]"; exit 0;;
    *) echo >&2 "nechapem argument: $arg"; exit 1;;
  esac
done

if [ -z "$wwwuser" ]; then
  echo >&2 "argument --www-user= je povinny, napr. --www-user=www-data (alebo --www-user=${me} ak webserver bezi pod tebou)"
  exec "$0" --help
fi

if ! [ -w app ]; then
  echo "${bold}init_all.sh treba spustat pod uzivatelom, co vlastni zdrojaky.${normal}"
  exit 1
fi

if ! [ -O app ]; then
  echo "${bold}init_all.sh treba spustat pod uzivatelom, co vlastni zdrojaky.${normal}
tento ich sice moze prepisovat, ale nevlastni ich, takze ked vyrobi novy subor,
uz nebudu mat vsetky subory toho isteho vlastnika.
(ak vies, co robis, docasne tuto kontrolu mozes v init_all.sh zakomentovat.)"
  exit 1
fi

rm -rf app/cache/ app/logs/
for dir in app/cache/ app/logs/ db/; do
  if ! [ -d "$dir" ]; then
    echo "vyrabam $dir"
    mkdir "$dir"
    if [ "$wwwuser" != "$me" ]; then
      setfacl -R -m "u:$wwwuser:rwx" -m "u:$me:rwx" -m o::--- "$dir"
      setfacl -dR -m "u:$wwwuser:rwx" -m "u:$me:rwx" -m o::--- "$dir"
    fi
  fi
done

if ! [ -f app/config/config_local.yml ]; then
  echo "vyrabam app/config/config_local.yml"
  sed_secret=$(base64 /dev/urandom | tr -dc 0-9a-f | head -c32)
  sed_script="s/secret:.*/secret: ${sed_secret}/"
  if [ -n "$mysqlopt" ]; then
    mysqlhost=${mysqlopt%%,*}
    mysqlopt=${mysqlopt#*,}
    mysqldbname=${mysqlopt%%,*}
    mysqlopt=${mysqlopt#*,}
    mysqluser=${mysqlopt%%,*}
    mysqlpassword=${mysqlopt#*,}
    sed_script+="
	  /database:/,/allow_db_reset:/ {
	    # zakomentuj pdo_sqlite blok
	    s/^ /# /
	    /^$/,$ {
	      # odkomentuj pdo_mysql blok a nastav detaily
	      s/^#//
	      s/(host: +).*/\1${mysqlhost}/
	      s/(dbname: +).*/\1${mysqldbname}/
	      s/(user: +).*/\1${mysqluser}/
	      s/(password: +).*/\1${mysqlpassword}/
	    }
	  }
    "
  fi
  sed -r "$sed_script" app/config/config_local.yml.dist > app/config/config_local.yml
  setfacl -b -m u::rw- -m "u:$wwwuser:r--" -m g::--- -m o::--- app/config/config_local.yml
elif [ -n "$mysqlopt" ]; then
  echo "config_local.yml uz existuje, takze ignorujem --mysql argument"
fi

if command -v composer &> /dev/null; then
  composerbin=composer
else
  composerbin=./composer.phar
  if ! [ -e ./composer.phar ]; then
    echo "stahujem composer.phar"
    curl -fsSL https://getcomposer.org/installer | php -- --1
  fi
fi

echo "spustam composer install"
"$composerbin" install

if [ -z "$skipadvice" ]; then
echo "
1. ak chces ${bold}pristup do AISu${normal}, nastav ${bold}libfajr_login${normal} v app/config/config_local.yml
2. ak chces ${bold}mysql${normal} namiesto sqlite, nastav ${bold}database${normal} v app/config/config_local.yml
3. vyrob novu databazu a daj do nej nejake data
4. ak mas ${bold}produkcne data${normal}, pre istotu nastav ${bold}allow_db_reset${normal} v app/config/config_local.yml (ale uz dlho to nic nerobi)
"
fi
