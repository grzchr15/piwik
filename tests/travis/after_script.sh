#!/bin/bash

# output contents of files w/ debugging info to screen
cat /var/log/nginx/error.log
cat $TRAVIS_BUILD_DIR/tmp/php-fpm.log
cat $TRAVIS_BUILD_DIR/tmp/logs/piwik.log
cat $TRAVIS_BUILD_DIR/config/config.ini.php

# upload test artifacts (for debugging travis failures)
./tests/travis/upload_artifacts.sh

# change directory back to root travis dir
cd $TRAVIS_BUILD_DIR