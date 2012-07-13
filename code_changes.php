#!/usr/bin/php
<?php

if (count($argv) < 2) {
    die("{$argv[0]} [codename] [date]\n");
}

if (count($argv) < 3) {
  $RELEASE_DATE = rtrim(`date +%Y-%m-%d`);
  $RELEASE_HOTFIX = '';
} else {
  $RELEASE_DATE = $argv[2];
  $RELEASE_HOTFIX = substr($RELEASE_DATE, 10);
}

if (substr($argv[1], 0, 2) === 'rc') {
    preg_match('/^rc(\d*)(.*)$/', $argv[1], $matches);
    if (!$matches[1]) {
        $matches[1] = 1;
    }
    $RC = $matches[1];
    $argv[1] = $matches[2] . ' RC' . $matches[1];
}

$RELEASE_NAME = $argv[1];
$RELEASE = $RELEASE_DATE . ' "' . $RELEASE_NAME . '"';
$ROOT = 'dokuwiki';
$LAST_RELEASE = 'old-stable';
$RELEASE_BRANCH = 'master';

if (file_exists('VERSION') && file_exists('.git')) {
  echo "Working from stable or old-stable checkout.\n";
  $ROOT = '.';
  die("Probably not supported anymore\n");
} else {
  get_git();
}

update_updatecheck();

if (!$RELEASE_HOTFIX) {
    // FIXME might be necessary!
    update_installphp();
    update_deletedfiles();
}

if ($RELEASE_BRANCH === 'old-stable') {
    switch_to_branch($RELEASE_BRANCH);
}
commit_releasepreps();

if (!$RELEASE_HOTFIX) {
    merge_masterintostable();
} elseif ($RELEASE_BRANCH === 'stable') {
    switch_to_branch($RELEASE_BRANCH);
    cherrypick('master');
}

update_version();
commit_release();
tag_stable();

function get_git() {
    global $ROOT;
    system("git clone git@github.com:splitbrain/dokuwiki.git $ROOT");
    global $RELEASE_HOTFIX;
    if ($RELEASE_HOTFIX) {
        global $RELEASE_NAME;
        global $RELEASE_BRANCH;
        $V = null;
        $releases = array('stable', 'old-stable');
        $cur_release = null;
        while (strpos($V, '"' . $RELEASE_NAME . '"') === false) {
            if (count($releases) === 0) {
                die("Release $RELEASE_NAME not found\n");
            }
            $cur_release = array_shift($releases);
            switch_to_branch($cur_release);
            $V = file_get_contents("$ROOT/VERSION");
        }
        $RELEASE_BRANCH = $cur_release;
        switch_to_branch('master');
    }
}

function switch_to_branch($branch) {
    global $ROOT;
    system("cd $ROOT && git checkout $branch");
}

function cherrypick($commitish) {
    global $ROOT;
    system("cd $ROOT && git cherry-pick $commitish");
}

function update_installphp() {
    global $RELEASE_DATE;
    global $ROOT;

    $dokuhash = md5(preg_replace("/(\015\012)|(\015)/","\012",
                                 file_get_contents($ROOT . '/conf/dokuwiki.php')));

    $installphp = file_get_contents($ROOT . '/install.php');

    $installphp_split1 = explode('$dokuwiki_hash = ', $installphp, 2);
    $installphp_split2 = explode(');', $installphp_split1[1], 2);

    $installphp_split = array($installphp_split1[0],
                              $installphp_split2[0],
                              $installphp_split2[1]);

    $hashes = eval('return ' . $installphp_split[1] . ');');
    $new_hashes = array();

    foreach($hashes as $release => $hash) {
        if (strpos($release, 'rc') === 0) {
            continue;
        }
        $new_hashes[$release] = $hash;
    }
    $new_hashes[$RELEASE_DATE] = $dokuhash;
    ksort($new_hashes);

    $hash_string = var_export($new_hashes, true);
    $hash_string = str_replace(array('array (', '  ', ' ='),
                               array('array(', '    ', '   ='), $hash_string) . ';';
    file_put_contents($ROOT . '/install.php', $installphp_split[0] . '$dokuwiki_hash = ' .
                                              $hash_string . $installphp_split[2]);
    if (strpos(`php $ROOT/install.php`, '<input type="text" name="d[title]" id="title" value="" style="width: 20em;" />') === false) {
        die ('Failed to update install.php');
    }
    echo "install.php updated\n";
}

function update_updatecheck() {
    global $ROOT;
    global $RELEASE_HOTFIX;
    $dokuphp = file_get_contents("$ROOT/doku.php");
    if (!preg_match('/\$updateVersion = (\d+(?:\.\d+)?);/', $dokuphp, $matches)) {
        die ("Could not parse doku.php\n");
    }
    $old = $matches[1];
    $new = explode('.', $old);
    if ($RELEASE_HOTFIX && count($new) === 1) {
      $new[] = '0';
    }
    $new[count($new) - 1] = intval($new[count($new) - 1]) + 1;
    $dokuphp = str_replace('$updateVersion = ' . $old . ';',
                           '$updateVersion = ' . implode('.', $new) . ';', $dokuphp);
    file_put_contents("$ROOT/doku.php", $dokuphp);
    if (`php -l $ROOT/doku.php` !== "No syntax errors detected in $ROOT/doku.php\n") {
        die ('Could not update doku.php');
    }
    echo "doku.php updated\n";
}

function update_deletedfiles() {
    global $ROOT;
    global $RELEASE_DATE;

    $deletedfiles = file_get_contents("$ROOT/data/deleted.files");
    $newly_deleted = rtrim(`cd $ROOT && git diff origin/old-stable..HEAD --summary | grep '^ delete'|awk '{print \$4}'|grep -v VERSION`);

    $in_head = true;
    $head = '';
    $pos = 0;
    $kill_this = false;

    while ($pos < strlen($deletedfiles) - 1) {
        $old_pos = $pos;
        $pos = strpos($deletedfiles, "\n", $old_pos) + 1;
        if ($pos === false) {
            break;
        }
        if (substr($deletedfiles, $old_pos, 1) === '#') {
            if ($in_head) {
                $head .= substr($deletedfiles, $old_pos, $pos - $old_pos);
                continue;
            }
            $kill_this = false;
            if (strpos(substr($deletedfiles, $old_pos, $pos - $old_pos), 'rc') !== false) {
                $kill_this = true;
                continue;
            }
            break;
        }
        if ($in_head) {
            $in_head = false;
            $head .= "\n# removed in $RELEASE_DATE\n$newly_deleted";
        }
    }
    file_put_contents("$ROOT/data/deleted.files", $head . "\n\n" . substr($deletedfiles, $old_pos));
    echo "data/deleted.files updated\n";
    echo "\ninsert into https://www.dokuwiki.org/install%3Aupgrade#files_to_remove\n----\n# removed in $RELEASE_DATE\n$newly_deleted\n----\n";
}

function commit_releasepreps() {
    global $ROOT;
    system("cd $ROOT && git commit -a -m 'Release preparations'");
    echo "Release preparations commited\n";
}

function merge_masterintostable() {
    global $ROOT;
    $run = `cd $ROOT && git checkout stable && git merge master`;
    if(strpos($run, "Merge conflict in doku.php") !== false) {
        // Need to hard-reset doku.php
        system("cd $ROOT && git checkout master -- doku.php && git commit -a");
    }
    echo "Master merged into stable\n";
}

function update_version() {
    global $RELEASE;
    global $ROOT;
    file_put_contents("$ROOT/VERSION", $RELEASE . "\n");
    echo "Updated VERSION file\n";
}

function commit_release() {
    global $ROOT;
    global $RELEASE;
    global $RC;
    global $RELEASE_HOTFIX;
    $msg = ($RELEASE_HOTFIX ? 'Hotfix release ' : 'Release ') . ($RC ? 'candidate rc' : '') . $RELEASE;
    system("cd $ROOT && git commit -m '$msg' VERSION") || die();
    echo "Commited release\n";
}

function tag_stable() {
    global $RELEASE;
    global $RELEASE_DATE;
    global $ROOT;
    global $RC;
    $tag = 'release_' . ($RC ? 'candidate' : 'stable');
    system("cd $ROOT && git tag -s -m '$RELEASE' '{$tag}_$RELEASE_DATE'") || die();
    echo "Commit tagged\n";
}
