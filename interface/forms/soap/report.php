<?php

/**
 * soap form
 * Forms generated from formsWiz
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(dirname(__FILE__) . '/../../globals.php');
require_once($GLOBALS["srcdir"] . "/api.inc.php");

function soap_report($pid, $encounter, $cols, $id, $as_csv = false)
{
    $cols = 1; // force always 1 column
    $count = 0;
    $data = formFetch("form_soap", $id);
    if ($as_csv) {
        // CSV headers:
        echo csvEscape(xlt("Subjective")) . ",";
        echo csvEscape(xlt("Objective")) . ",";
        echo csvEscape(xlt("Assessment")) . ",";
        echo csvEscape(xlt("Plan")) . "\n";
    }
    if ($data) {
        if (!$as_csv) {
            print "<table><tr>";
            foreach ($data as $key => $value) {
                if ($key == "id" || $key == "pid" || $key == "user" || $key == "groupname" || $key == "authorized" || $key == "activity" || $key == "date" || $value == "" || $value == "0000-00-00 00:00:00") {
                    continue;
                }

                if ($value == "on") {
                    $value = "yes";
                }

                $key = ucwords(str_replace("_", " ", $key));
                $count++;
                                                                                //Updated by Sherwin 10/24/2016
                print "<td><span class=bold>" . xlt($key) . ": </span><span class=text>" . nl2br(text($value)) . "</span></td>";
                if ($count == $cols) {
                    $count = 0;
                    print "</tr><tr>\n";
                }
            }

            print "</tr></table>";
        } else {
            echo csvEscape($data['subjective']) . "," . csvEscape($data['objective']) . "," . csvEscape($data['assessment']) . "," . csvEscape($data['plan']) . "\n";
        }
    }
}
