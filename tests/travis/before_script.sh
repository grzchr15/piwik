#!/bin/bash

# for travis_retry
source travis-helper.sh

# do not use the Zend allocator on PHP 5.3 since it will randomly segfault after program execution
if [ "$TRAVIS_PHP_VERSION" == "5.3" ]; then
    export USE_ZEND_ALLOC=0
fi

# print out mysql information
mysql --version
mysql -e "SELECT VERSION();"

# configure mysql
mysql -e "SET GLOBAL sql_mode = 'NO_ENGINE_SUBSTITUTION'" # Travis default
# Uncomment to enable sql_mode STRICT_TRANS_TABLES (new default in Mysql 5.6)
#- mysql -e "SET GLOBAL sql_mode = 'NO_ENGINE_SUBSTITUTION,STRICT_TRANS_TABLES'"
mysql -e "SELECT @@sql_mode;"
mysql -e "SHOW GLOBAL VARIABLES;"

# Setup Piwik stack
./tests/travis/initiate_ui_tests.sh

# travis now complains about this failing 9 times out of 10, so removing it. hopefully the random failures it prevented won't come back
# - travis_retry composer self-update

travis_retry composer install

# print out more debugging info
uname -a
date
php -r "var_dump(gd_info());"
mysql -e 'create database piwik_tests;'

# Make sure we use Python 2.6
travis_retry sudo add-apt-repository ppa:fkrull/deadsnakes -y
travis_retry sudo apt-get update
travis_retry sudo apt-get install python2.6 python2.6-dev

# Log Analytics works with Python 2.6 or 2.7 but we want to test on 2.6
python2.6 --version
python --version

./tests/travis/prepare.sh
./tests/travis/setup_webserver.sh

cd tests/PHPUnit