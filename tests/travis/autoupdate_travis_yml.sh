#!/bin/bash

# if we're on master, check if .travis.yml is out of date. if github token is supplied we will try to auto-update,
# otherwise we just print a message and exit.

# only run auto-update for first travis job and if not a pull request
if [ "$TRAVIS_PULL_REQUEST" == "false" ] && [[ "$TRAVIS_JOB_NUMBER" == *.1 ]]; then
    GENERATE_TRAVIS_YML_COMMAND="$GENERATE_TRAVIS_YML_COMMAND --dump=./generated.travis.yml"
    $GENERATE_TRAVIS_YML_COMMAND

    echo "diffing:"
    diff .travis.yml generated.travis.yml
    echo $?

    if [ $? eq 1 ]; then
        if [ "$GITHUB_USER_TOKEN" != "" ]; then
            cp generated.travis.yml .travis.yml

            LAST_COMMIT_MESSAGE=$(git log -1 HEAD --pretty=format:%s)

            if [ "$LAST_COMMIT_MESSAGE" == "" ] || grep -q ".travis.yml file is out of date" <<< "$LAST_COMMIT_MESSAGE"; then
                git add .travis.yml
                git commit -m ".travis.yml file is out of date, auto-updating .travis.yml file."

                git config credential.helper "store --file=.git/credentials"
                echo "https://$GITHUB_USER_TOKEN:@github.com" > .git/credentials

                git push origin $TRAVIS_BRANCH
            else
                echo "Last commit message was '$LAST_COMMIT_MESSAGE', possible recursion in auto-update, aborting."
            fi

            echo ""
            echo "Generated .travis.yml:"
            cat generated.travis.yml

            exit 1
        else
            echo "${RED}Your .travis.yml file is out of date! Please update it using the generate:travis-yml command.${RESET}" # TODO: should print on travis.sh so always visible
        fi
    else
        echo ".travis.yml file is up-to-date."
    fi
else
    echo "Building for pull request or not first job, skipping .travis.yml out of date check."
    echo ""
    echo "TRAVIS_PULL_REQUEST=$TRAVIS_PULL_REQUEST"
    echo "TRAVIS_JOB_NUMBER=$TRAVIS_JOB_NUMBER"
fi