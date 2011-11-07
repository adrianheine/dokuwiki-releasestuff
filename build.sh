#!/bin/sh

BDIR=/tmp/buildplace

DATE=`date '+%Y-%m-%d'`

if [ ! -z "$1" ]
then
    TYPE='rc'
    TAG='release_candidate'
else
    TYPE=''
    TAG='release_stable'
fi

echo "Please give a code name:"
read CODE

#checklist
echo $TYPE$DATE "\"$CODE\""
echo
echo "did you tag the stable branch with '${TAG}_$DATE'?"
echo "  git tag -s 'release_candidate_2010-10-07' -m '$TYPE$DATE \"$CODE\"'"
echo
read bla
echo "did you merge master into the stable branch?"
echo
echo
echo -n "hit enter to continue"
read foo


cd $BDIR || exit

rm -rf dokuwiki*

git clone https://github.com/splitbrain/dokuwiki.git
cd dokuwiki || exit
git checkout -b stable origin/stable

rm -rf .git*
rm -rf _test
rm -rf _cs
rm -f test.php
rm -f langcheck.php
rm -f build.sh
rm -f inc/*.bak
mkdir data/pages/playground
echo "====== PlayGround ======" > data/pages/playground/playground.txt
#echo $TYPE$DATE "\"$CODE\"" > VERSION

#mv -f .htaccess.dist .htaccess
#mv -f conf/users.auth.dist conf/users.auth
#mv -f conf/acl.auth.dist conf/acl.auth
rm -f changes.log
rm -f conf/local.php

cd ..

mv dokuwiki dokuwiki-$TYPE$DATE

tar -czvf dokuwiki-$TYPE$DATE.tgz dokuwiki-$TYPE$DATE

echo
echo "now upload: $BDIR/dokuwiki-$TYPE$DATE.tgz"

