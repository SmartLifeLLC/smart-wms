#!/usr/bin/bash

groupadd laravel
gpasswd -a root laravel
gpasswd -a apache laravel
gpasswd -a www-data laravel

find ./ -type d -exec chmod 755 {} \;
find ./ -type f -exec chmod 644 {} \;

chown -R :laravel ./storage
chown -R :laravel ./bootstrap/

find ./storage -type d -exec chmod 775 {} \;
find ./storage -type f -exec chmod 664 {} \;
find ./bootstrap/cache -type d -exec chmod 775 {} \;
find ./bootstrap/cache -type f -exec chmod 664 {} \;

find ./storage -type d -exec chmod g+s {} \;
find ./bootstrap/cache -type d -exec chmod g+s {} \;
setfacl -R -d -m g::rwx ./storage
setfacl -R -d -m g::rwx ./bootstrap/cache
