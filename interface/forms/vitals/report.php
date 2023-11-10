<?php

/**
 * vitals report.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2021 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once($GLOBALS["srcdir"] . "/api.inc.php");
require_once($GLOBALS['fileroot'] . "/library/patient.inc.php");

function US_weight($pounds, $mode = 1)
{

    if ($mode == 1) {
        return $pounds . " " . xl('lb') ;
    } else {
        $pounds_int = floor($pounds);
        $ounces = round(($pounds - $pounds_int) * 16);
        return $pounds_int . " " . xl('lb') . " " . $ounces . " " . xl('oz');
    }
}

function vitals_report($pid, $encounter, $cols, $id, $print = true, $as_csv = false)
{
    $count = 0;
    $data = formFetch("form_vitals", $id);
    $patient_data = getPatientData($GLOBALS['pid']);
    $patient_age = getPatientAge($patient_data['DOB']);
    $is_pediatric_patient = ($patient_age <= 20 || (preg_match('/month/', $patient_age)));

    $vitals = "";
    if ($data) {
        if (!$as_csv) {
            $vitals .= "<table><tr>";
        } else {
            foreach ($data as $key => $value) {
                // CSV headers:
                if ($key == "uuid" || $key == "id" || $key == "pid" || $key == "user" || $key == "groupname" || $key == "authorized" || $key == "activity" || $key == "date"
                    || $key == "bps") {
                    continue;
                }
                if ($vitals != '') {
                    $vitals .= ",";
                }
                if ($key == "bpd") {
                    $vitals .= csvEscape(xlt("Blood Pressure"));
                } else if ($key == "ped_weight_height") {
                    $vitals .= csvEscape(xlt("Pediatric Height Weight Percentile"));
                } else if ($key == "ped_bmi") {
                    $vitals .= csvEscape(xlt("Pediatric BMI Percentile"));
                } else if ($key == "ped_head_circ") {
                    $vitals .= csvEscape(xlt("Pediatric Head Circumference Percentile"));
                } else {
                    $vitals .= csvEscape(xlt(ucwords(str_replace("_", " ", $key))));
                }
            }
            $vitals .= "\n";
        }

        foreach ($data as $key => $value) {
            if (!$as_csv) {
                if (
                    $key == "uuid" ||
                    $key == "id" || $key == "pid" ||
                    $key == "user" || $key == "groupname" ||
                    $key == "authorized" || $key == "activity" ||
                    $key == "date" || $value == "" ||
                    $value == "0000-00-00 00:00:00" || $value == "0.0"
                ) {
                    // skip certain data
                    continue;
                }
            } else if ($key == "uuid" || $key == "id" || $key == "pid" || $key == "user" || $key == "groupname" || $key == "authorized" || $key == "activity" || $key == "date") {
                continue;
            } else if (substr($vitals, -1) != "\n") {
                $vitals .= ",";
            }

            if ($value == "on") {
                $value = "yes";
            }

            if ($key == 'inhaled_oxygen_concentration') {
                $value .= " %";
            }

            $key = ucwords(str_replace("_", " ", $key));

            //modified by BM 06-2009 for required translation
            if ($key == "Temp Method" || $key == "BMI Status") {
                if ($key == "BMI Status") {
                    if ($is_pediatric_patient) {
                        $value = "See Growth-Chart";
                    }
                }

                if (!$as_csv) {
                    $vitals .= '<td><div class="bold" style="display:inline-block">' . xlt($key) . ': </div></td><td><div class="text" style="display:inline-block">' . xlt($value) . "</div></td>";
                } else {
                    $vitals .= csvEscape(xlt($value));
                }
            } elseif ($key == "Bps" || $key == "Bpd") {
                if ($key == "Bps") {
                    $bps = $value;
                } else if ($key == "Bpd") {
                    $bpd = $value;
                }
                if (!empty($bps) && !empty($bpd) && $bps && $bpd) {
                    if (!$as_csv) {
                        $vitals .= "<td><div class='font-weight-bold d-inline-block'>" . xlt('Blood Pressure') . ": </div></td><td><div class='text' style='display:inline-block'>" . text($bps) . "/" . text($bpd)  . "</div></td>";
                    } else {
                        $vitals .= csvEscape($bps . "/" . $bpd);
                    }
                } else if (!$as_csv) {
                    continue;
                } else if (!$key == "Bps") {
                    $vitals .= '""';
                }
            } elseif ($key == "Weight") {
                $value = floatval($value);
                $convValue = number_format($value * 0.45359237, 2);
                // show appropriate units
                $mode = $GLOBALS['us_weight_format'];
                if (!$as_csv) {
                    $vitals .= "<td><div class='font-weight-bold d-inline-block'>" . xlt($key) . ": </div></td><td><div class='text' style='display:inline-block'>";
                    if ($GLOBALS['units_of_measurement'] == 2) {
                        $vitals .= text($convValue) . " " . xlt('kg') . " (" . text(US_weight($value, $mode)) . ")";
                    } elseif ($GLOBALS['units_of_measurement'] == 3) {
                        $vitals .= text(US_weight($value, $mode));
                    } elseif ($GLOBALS['units_of_measurement'] == 4) {
                        $vitals .= text($convValue) . " " . xlt('kg');
                    } else { // = 1 or not set
                        $vitals .= text(US_weight($value, $mode)) . " (" . text($convValue) . " " . xlt('kg')  . ")";
                    }
                    $vitals .= "</div></td>";
                } else {
                    $vitals .= csvEscape($convValue . " " . xlt('kg') . "," . US_weight($value, $mode));
                }
            } elseif ($key == "Height" || $key == "Waist Circ"  || $key == "Head Circ") {
                $value = floatval($value);
                $convValue = number_format(round($value * 2.54, 1), 2);
                // show appropriate units
                if (!$as_csv) {
                    $vitals .= "<td><div class='font-weight-bold d-inline-block'>" . xlt($key) . ": </div></td><td><div class='text' style='display:inline-block'>";
                    if ($GLOBALS['units_of_measurement'] == 2) {
                        $vitals .= text($convValue) . " " . xlt('cm') . " (" . text($value) . " " . xlt('in')  . ")";
                    } elseif ($GLOBALS['units_of_measurement'] == 3) {
                        $vitals .= text($value) . " " . xlt('in');
                    } elseif ($GLOBALS['units_of_measurement'] == 4) {
                        $vitals .= text($convValue) . " " . xlt('cm');
                    } else { // = 1 or not set
                        $vitals .= text($value) . " " . xlt('in') . " (" . text($convValue) . " " . xlt('cm')  . ")";
                    }
                    $vitals .= "</div></td>";
                } else {
                    $vitals .= csvEscape($convValue . " " . xlt('cm') . "," . $value . " " . xlt('in'));
                }
            } elseif ($key == "Temperature") {
                $value = floatval($value);
                $convValue = number_format((($value - 32) * 0.5556), 2);
                // show appropriate units
                if (!$as_csv) {
                    $vitals .= "<td><div class='font-weight-bold d-inline-block'>" . xlt($key) . ": </div></td><td><div class='text' style='display:inline-block'>";
                    if ($GLOBALS['units_of_measurement'] == 2) {
                        $vitals .= text($convValue) . " " . xlt('C') . " (" . text($value) . " " . xlt('F')  . ")";
                    } elseif ($GLOBALS['units_of_measurement'] == 3) {
                        $vitals .= text($value) . " " . xlt('F');
                    } elseif ($GLOBALS['units_of_measurement'] == 4) {
                        $vitals .= text($convValue) . " " . xlt('C');
                    } else { // = 1 or not set
                        $vitals .= text($value) . " " . xlt('F') . " (" . text($convValue) . " " . xlt('C')  . ")";
                    }
                    $vitals .= "</div></td>";
                } else {
                    $vitals .= csvEscape($convValue . " " . xlt('C') . "," . $value . " " . xlt('F'));
                }
            } elseif ($key == "Pulse" || $key == "Respiration"  || $key == "Oxygen Saturation" || $key == "BMI" || $key == "Oxygen Flow Rate") {
                $value = floatval($value);
                $c_value = number_format($value, 0);
                if (!$as_csv) {
                    $vitals .= "<td><div class='font-weight-bold d-inline-block'>" . xlt($key) . ": </div></td><td><div class='text' style='display:inline-block'>";
                }
                if ($key == "Oxygen Saturation") {
                    if (!$as_csv) {
                        $vitals .= text($c_value) . " " . xlt('%');
                    } else {
                        $vitals .= csvEscape($c_value .  xlt('%'));
                    }
                } elseif ($key == "Oxygen Flow Rate") {
                    $c_value = number_format($value, 2);
                    if (!$as_csv) {
                        $vitals .= text($c_value) . " " . xlt('l/min');
                    } else {
                        $vitals .= csvEscape($c_value . " " . xlt('l/min'));
                    }
                } elseif ($key == "BMI") {
                    if (!$as_csv) {
                        $vitals .= text($c_value) . " " . xlt('kg/m^2');
                    } else {
                        $vitals .= csvEscape($c_value . " " . xlt('kg/m^2'));
                    }
                } else if (!$as_csv) { //pulse and respirations
                    $vitals .= text($c_value) . " " . xlt('per min');
                } else {
                    $vitals .= csvEscape($c_value . " " . xlt('per min'));
                }
                if (!$as_csv) {
                    $vitals .= "</div></td>";
                }
            } elseif ($key == "Ped Weight Height" || $key == 'Ped Bmi' || $key == 'Ped Head Circ') {
                $value = floatval($value);
                if ($is_pediatric_patient) {
                    $c_value = number_format($value, 0);
                    if (!$as_csv) {
                        $vitals .= "<td><div class='font-weight-bold d-inline-block'>";
                        if ($key == "Ped Weight Height") {
                            $vitals .= "<td><div class='font-weight-bold d-inline-block'>" . xlt("Pediatric Height Weight Percentile") . ": </div></td><td><div class='text' style='display:inline-block'>" . text($c_value) . " " . xlt('%') . "</div></td>";
                        } elseif ($key == "Ped Bmi") {
                            $vitals .= "<td><div class='font-weight-bold d-inline-block'>" . xlt("Pediatric BMI Percentile") . ": </div></td><td><div class='text' style='display:inline-block'>" . text($c_value) . " " . xlt('%') . "</div></td>";
                        } elseif ($key == "Ped Head Circ") {
                            $vitals .= "<td><div class='font-weight-bold d-inline-block'>" . xlt("Pediatric Head Circumference Percentile") . ": </div></td><td><div class='text' style='display:inline-block'>" . text($c_value) . " " . xlt('%') . "</div></td>";
                        }
                    } else {
                        $vitals .= csvEscape($c_value . xlt('%'));
                    }
                }
            } else if (!$as_csv) {
                $vitals .= "<td><div class='font-weight-bold d-inline-block'>" . xlt($key) . ": </div></td><td><div class='text' style='display:inline-block'>" . text($value) . "</div></td>";
            } else {
                $vitals .= csvEscape($value);
            }

            if (!$as_csv) {
                $count++;

                if ($count == $cols) {
                    $count = 0;
                    $vitals .= "</tr><tr>\n";
                }
            }
        }

        if (!$as_csv) {
            $vitals .= "</tr></table>";
        } else {
            $vitals .= "\n";
        }
    }

    if ($print) {
        echo $vitals ;
    } else {
        return $vitals;
    }
}
