#!/usr/bin/php
<?php

if (count($argv) < 2) {
    die("{$argv[0]} [codename]\n");
}

$RELEASE_DATE = rtrim(`date +%Y-%m-%d`);
$RELEASE = $RELEASE_DATE . ' "' . $argv[1] . '"';
$ROOT = 'dokuwiki';

get_git();
update_installphp();
update_updatecheck();
update_deletedfiles();
commit_releasepreps();
merge_masterintostable();
update_version();
commit_release();
tag_stable();

function get_git() {
    global $ROOT;
    system("git clone git@github.com:splitbrain/dokuwiki.git $ROOT
            cd $ROOT
    ");
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
    $dokuphp = file_get_contents("$ROOT/doku.php");
    if (!preg_match('/\$updateVersion = (\d{2});/', $dokuphp, $matches)) {
        die ('Could not parse doku.php');
    }
    $old = $matches[1];
    $new = intval($old) + 1;
    $dokuphp = str_replace('$updateVersion = ' . $old . ';',
                           '$updateVersion = ' . $new . ';', $dokuphp);
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
    system("cd $ROOT && git checkout stable && git merge master");
    echo "Master merged into stable\n";
}

function update_version() {
    global $RELEASE;
    global $ROOT;
    file_put_contents("$ROOT/VERSION", $RELEASE);
    echo "Updated VERSION file\n";
}

function commit_release() {
    global $ROOT;
    global $RELEASE;
    system("cd $ROOT && git commit -m 'Release $RELEASE' VERSION");
    echo "Commited release\n";
}

function tag_stable() {
    global $RELEASE;
    global $RELEASE_DATE;
    global $ROOT;
    system("cd $ROOT && git tag -s -m '$RELEASE' 'release_stable_" . $RELEASE_DATE . "'");
    echo "Commit tagged\n";
}
