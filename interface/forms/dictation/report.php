<?php

/**
 * dictation report.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once dirname(__FILE__) . '/../../globals.php';
require_once $GLOBALS["srcdir"] . "/api.inc.php";

function dictation_report($pid, $encounter, $cols, $id, $as_csv = false)
{
    $cols = 1; // force always 1 column
    $count = 0;
    $data = formFetch("form_dictation", $id);
    if ($as_csv) {
        // CSV headers:
        echo csvEscape(xlt("Dictation")) . ",";
        echo csvEscape(xlt("Additional Notes")) . "\n";
    }
    if ($data) {
        if (!$as_csv) {
            foreach ($data as $key => $value) {
                if ($key == "id" || $key == "pid" || $key == "user" 
                    || $key == "groupname" || $key == "authorized" || $key == "activity" 
                    || $key == "date" || $value == "" || $value == "0000-00-00 00:00:00"
                ) {
                    continue;
                }

                if ($value == "on") {
                    $value = "yes";
                }

                $key = ucwords(str_replace("_", " ", $key));
                print "<h3>" . xlt($key) . ": </h3>" .
                    "<p>" . nl2br(text($value)) . "</p>";
                $count++;
            }
        } else {
            echo csvEscape($data['dictation']) . "," . csvEscape($data['additional_notes']) . "\n";
        }
    }
}
