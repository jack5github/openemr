<?php

/**
 * Patient custom report.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Ken Chapple <ken@mi-squared.com>
 * @author    Tony McCormick <tony@mi-squared.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2017-2020 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once "../../globals.php";
require_once "$srcdir/forms.inc.php";
require_once "$srcdir/pnotes.inc.php";
require_once "$srcdir/patient.inc.php";
require_once "$srcdir/options.inc.php";
require_once "$srcdir/lists.inc.php";
require_once "$srcdir/report.inc.php";
require_once dirname(__file__) . "/../../../custom/code_types.inc.php";
require_once $GLOBALS['srcdir'] . '/ESign/Api.php';
require_once $GLOBALS["include_root"] . "/orders/single_order_results.inc.php";
require_once "$srcdir/appointments.inc.php";
require_once $GLOBALS['fileroot'] . "/controllers/C_Document.class.php";

use ESign\Api;
use Mpdf\Mpdf;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\MedicalDevice\MedicalDevice;
use OpenEMR\Pdf\Config_Mpdf;
use OpenEMR\Services\FacilityService;

if (!AclMain::aclCheckCore('patients', 'pat_rep')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Custom Report")]);
    exit;
}

$facilityService = new FacilityService();

$staged_docs = array();
$archive_name = '';

// For those who care that this is the patient report.
$GLOBALS['PATIENT_REPORT_ACTIVE'] = true;

$PDF_OUTPUT = empty($_POST['pdf']) ? 0 : intval($_POST['pdf']);
$PDF_FAX = empty($_POST['fax']) ? 0 : intval($_POST['fax']);
if ($PDF_FAX) {
    $PDF_OUTPUT = 1;
}

if ($PDF_OUTPUT) {
    $config_mpdf = Config_Mpdf::getConfigMpdf();
    // special settings for patient custom report that are necessary for mpdf
    $config_mpdf['margin_top'] = $config_mpdf['margin_top'] * 1.5;
    $config_mpdf['margin_bottom'] = $config_mpdf['margin_bottom'] * 1.5;
    $config_mpdf['margin_header'] = $GLOBALS['pdf_top_margin'];
    $config_mpdf['margin_footer'] =  $GLOBALS['pdf_bottom_margin'];
    $pdf = new mPDF($config_mpdf);
    if ($_SESSION['language_direction'] == 'rtl') {
        $pdf->SetDirectionality('rtl');
    }
    ob_start();
} // end pdf conditional.

// get various authorization levels
$auth_notes_a = AclMain::aclCheckCore('encounters', 'notes_a');
$auth_notes = AclMain::aclCheckCore('encounters', 'notes');
$auth_coding_a = AclMain::aclCheckCore('encounters', 'coding_a');
$auth_coding = AclMain::aclCheckCore('encounters', 'coding');
$auth_relaxed = AclMain::aclCheckCore('encounters', 'relaxed');
$auth_med = AclMain::aclCheckCore('patients', 'med');
$auth_demo = AclMain::aclCheckCore('patients', 'demo');

$esignApi = new Api();

$printable = empty($_GET['printable']) ? false : true;
if ($PDF_OUTPUT) {
    $printable = true;
}
$csv = empty($_GET['csv']) ? false : true;

unset($_GET['printable']);
unset($_GET['csv']);

// Number of columns in tables for insurance and encounter forms.
$N = $PDF_OUTPUT ? 4 : 6;

$first_issue = 1;

function getContent()
{
    global $web_root, $webserver_root;
    $content = ob_get_clean();
    // Fix a nasty mPDF bug - it ignores document root!
    $i = 0;
    $wrlen = strlen($web_root);
    $wsrlen = strlen($webserver_root);
    while (true) {
        $i = stripos($content, " src='/", $i + 1);
        if ($i === false) {
            break;
        }

        if (substr($content, $i + 6, $wrlen) === $web_root 
            && substr($content, $i + 6, $wsrlen) !== $webserver_root
        ) {
            $content = substr($content, 0, $i + 6) . $webserver_root . substr($content, $i + 6 + $wrlen);
        }
    }

    return $content;
}

function postToGet($arin)
{
    $getstring = "";
    foreach ($arin as $key => $val) {
        if (is_array($val)) {
            foreach ($val as $k => $v) {
                $getstring .= attr_url($key . "[]") . "=" . attr_url($v) . "&";
            }
        } else {
            $getstring .= attr_url($key) . "=" . attr_url($val) . "&";
        }
    }

    return $getstring;
}

function report_basename($pid)
{
    $ptd = getPatientData($pid, "fname,lname");
    // escape names for pesky periods hyphen etc.
    $esc = $ptd['fname'] . '_' . $ptd['lname'];
    $esc = str_replace(array('.', ',', ' '), '', $esc);
    $fn = basename_international(strtolower($esc . '_' . $pid . '_' . xl('report')));

    return array('base' => $fn, 'fname' => $ptd['fname'], 'lname' => $ptd['lname']);
}

function zip_content($source, $destination, $content = '', $create = true)
{
    if (!extension_loaded('zip')) {
        return false;
    }

    $zip = new ZipArchive();
    if ($create) {
        if (!$zip->open($destination, ZipArchive::CREATE)) {
            return false;
        }
    } else {
        if (!$zip->open($destination, ZipArchive::OVERWRITE)) {
            return false;
        }
    }

    if (is_file($source) === true) {
        $zip->addFromString(basename($source), file_get_contents($source));
    } elseif (!empty($content)) {
        $zip->addFromString(basename($source), $content);
    }

    return $zip->close();
}

if ($PDF_OUTPUT) { ?>
    <?php Header::setupAssets(['pdf-style', 'esign-theme-only']); ?>
<?php } else if ($csv) {
    header("Pragma: public");
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header("Content-Type: application/force-download");
    header("Content-Disposition: attachment; filename=patient_report.csv");
    header("Content-Description: File Transfer");
} else { ?>
<html>
<head>
    <?php Header::setupHeader(['esign-theme-only', 'search-highlight']); ?>
<?php }
if (!$csv) {
    // do not show stuff from report.php in forms that is encaspulated
    // by div of navigateLink class. Specifically used for CAMOS, but
    // can also be used by other forms that require output in the
    // encounter listings output, but not in the custom report. ?>

    <style>
      div.navigateLink {
        display: none;
      }

      .hilite2 {
        background-color: transparent;
      }

      .hilite, mark, .next {
        background-color: var(--yellow);
      }

      img {
        max-width: 700px;
      }
    </style>

    <?php if (!$PDF_OUTPUT) { ?>
        <?php // if the track_anything form exists, then include the styling
        if (file_exists(__DIR__ . "/../../forms/track_anything/style.css")) { ?>
            <?php Header::setupAssets('track-anything'); ?>
        <?php } ?>

</head>
    <?php } ?>

<body>
    <div class="container">
        <div id="report_custom w-100">  <!-- large outer DIV -->
<?php }
if (sizeof($_GET) > 0) {
    $ar = $_GET;
} else {
    $ar = $_POST;
}

if ($printable) {
    /*******************************************************************
     * $sql = "SELECT * FROM facility ORDER BY billing_location DESC LIMIT 1";
     *******************************************************************/
    $facility = null;
    if ($_SESSION['pc_facility']) {
        $facility = $facilityService->getById($_SESSION['pc_facility']);
    } else {
        $facility = $facilityService->getPrimaryBillingLocation();
    }

    /******************************************************************/
    // Setup Headers and Footers for mPDF only Download
    if ($PDF_OUTPUT) {
        echo genPatientHeaderFooter($pid);
    }

    // Use logo if it exists as 'practice_logo.gif' in the site dir
    // old code used the global custom dir which is no longer a valid
    $practice_logo = "";
    $plogo = glob("$OE_SITE_DIR/images/*");// let's give the user a little say in image format.
    $plogo = preg_grep('~practice_logo\.(gif|png|jpg|jpeg)$~i', $plogo);
    if (!empty($plogo)) {
        $k = current(array_keys($plogo));
        $practice_logo = $plogo[$k];
    }

    $logo = "";
    if (file_exists($practice_logo)) {
        $logo = $GLOBALS['OE_SITE_WEBROOT'] . "/images/" . basename($practice_logo);
    }

    echo genFacilityTitle(getPatientName($pid), $_SESSION['pc_facility'], $logo); ?>

<?php } else if (!$csv) { // not printable
    ?>
                <div class="border-bottom fixed-top px-5 pt-4 report_search_bar">
                    <div class="row">
                        <div class="col-md">
                            <input type="text" class="form-control" onkeyup="clear_last_visit();remove_mark_all();find_all();" name="search_element" id="search_element" />
                        </div>
                        <div class="col-md">
                            <div class="btn-group">
                                <button type="button" class="btn btn-primary btn-search" onClick="clear_last_visit();remove_mark_all();find_all();"><?php echo xlt('Find'); ?></button>
                                <button type="button" class="btn btn-primary" onClick="next_prev('prev');"><?php echo xlt('Prev'); ?></button>
                                <button type="button" class="btn btn-primary" onClick="next_prev('next');"><?php echo xlt('Next'); ?></button>
                            </div>
                        </div>
                        <div class="col-md">
                            <span><?php echo xlt('Match case'); ?></span>
                            <input type="checkbox" onClick="clear_last_visit();remove_mark_all();find_all();" name="search_case" id="search_case" />
                        </div>
                        <div class="col-md mb-2">
                            <span class="text font-weight-bold"><?php echo xlt('Search In'); ?>:</span>
                            <br />
                            <?php
                            $form_id_arr = array();
                            $form_dir_arr = array();
                            $last_key = '';
                            //ksort($ar);
                            foreach ($ar as $key_search => $val_search) {
                                if ($key_search == 'pdf' || $key_search == '') {
                                    continue;
                                }

                                if (($auth_notes_a || $auth_notes || $auth_coding_a || $auth_coding || $auth_med || $auth_relaxed)) {
                                    preg_match('/^(.*)_(\d+)$/', $key_search, $res_search);
                                    $form_id_arr[] = add_escape_custom($res_search[2] ?? '');
                                    $form_dir_arr[] = add_escape_custom($res_search[1] ?? '');
                                }
                            }

                            //echo json_encode(json_encode($array_key_id));
                            if (sizeof($form_id_arr) > 0) {
                                $query = "SELECT DISTINCT(form_name),formdir FROM forms WHERE form_id IN ( '" . implode("','", $form_id_arr) . "') AND formdir IN ( '" . implode("','", $form_dir_arr) . "')";
                                $arr = sqlStatement($query);
                                echo "<select multiple size='4' class='form-control' id='forms_to_search' onchange='clear_last_visit();remove_mark_all();find_all();' >";
                                while ($res_forms_ids = sqlFetchArray($arr)) {
                                    echo "<option value='" . attr($res_forms_ids['formdir']) . "' selected>" . text($res_forms_ids['form_name']) . "</option>";
                                }
                                echo "</select>";
                            }
                            ?>
                        </div>
                        <div class="col-md">
                            <span id='alert_msg' class='text-danger'></span>
                        </div>
                    </div>
                </div>
                <div id="backLink">
                    <a href="patient_report.php" onclick='top.restoreSession()'>
                        <span class='title'><?php echo xlt('Patient Report'); ?></span>
                        <span class='back'><?php echo text($tback); ?></span>
                    </a>
                </div>
                <br />
                <br />
                <a href="custom_report.php?printable=1&<?php print postToGet($ar); ?>" class='link_submit' target='new' onclick='top.restoreSession()'>
                    [<?php echo xlt('Printable Version'); ?>]
                </a>
                <a href='custom_report.php?csv=1&<?php print postToGet($ar); ?>' class='link_submit' target='new' onclick='top.restoreSession()'>
                    [<?php echo xlt('Export to CSV'); ?>]
                </a>
<?php } // end not printable

            // include ALL form's report.php files
            $inclookupres = sqlStatement("select distinct formdir from forms where pid = ? AND deleted=0", array($pid));
while ($result = sqlFetchArray($inclookupres)) {
    // include_once("{$GLOBALS['incdir']}/forms/" . $result["formdir"] . "/report.php");
    $formdir = $result['formdir'];
    if (substr($formdir, 0, 3) == 'LBF') {
        include_once $GLOBALS['incdir'] . "/forms/LBF/report.php";
    } else {
        include_once $GLOBALS['incdir'] . "/forms/$formdir/report.php";
    }
}

if ($PDF_OUTPUT) {
    $tmp_files_remove = array();
}

            // For each form field from patient_report.php...
            //
foreach ($ar as $key => $val) {
    if ($key == 'pdf') {
        continue;
    }

    // These are the top checkboxes (demographics, allergies, etc.).
    //
    if (stristr($key, "include_")) {
        if ($val == "recurring_days") {
            /// label/header for recurring days
            if (!$csv) {
                echo "<hr />";
                echo "<div class='text' id='appointments'>\n";
                print "<h4>" . xlt('Recurrent Appointments') . ":</h4>";
            } else {
                echo csvEscape("<BEGIN " . xlt("Recurrent Appointments") . ">") . "\n";
            }

            //fetch the data of the recurring days
            $recurrences = fetchRecurrences($pid);

            //print the recurring days to screen
            if (empty($recurrences)) { //if there are no recurrent appointments:
                if (!$csv) {
                    echo "<div class='text' >";
                    echo "<span>" . xlt('None{{Appointment}}') . "</span>";
                    echo "</div>";
                    echo "<br />";
                }
            } else {
                if ($csv) {
                    // CSV headers:
                    echo csvEscape(xlt('Appointment Category')) . ",";
                    echo csvEscape(xlt('Recurrence')) . ",";
                    echo csvEscape(xlt('End Date')) . "\n";
                }
                foreach ($recurrences as $row) {
                    //checks if there are recurrences and if they are current (git didn't end yet)
                    if (!recurrence_is_current($row['pc_endDate'])) {
                        continue;
                    }

                    if (!$csv) {
                        echo "<div class='text' >";
                        echo "<span>" . xlt('Appointment Category') . ': ' . xlt($row['pc_catname']) . "</span>";
                        echo "<br />";
                        echo "<span>" . xlt('Recurrence') . ': ' . text($row['pc_recurrspec']) . "</span>";
                        echo "<br />";

                        if (ends_in_a_week($row['pc_endDate'])) {
                            echo "<span class='text-danger'>" . xlt('End Date') . ': ' . text($row['pc_endDate']) . "</span>";
                        } else {
                            echo "<span>" . xlt('End Date') . ': ' . text($row['pc_endDate']) . "</span>";
                        }

                        echo "</div>";
                        echo "<br />";
                    } else {
                        echo csvEscape(xlt($row['pc_catname'])) . ",". csvEscape($row['pc_recurrspec']) . ","
                            . csvEscape($row['pc_endDate']) . "\n";
                    }
                }
            }
            if (!$csv) {
                echo "</div><br />";
            } else {
                echo csvEscape("<END " . xlt("Recurrent Appointments") . ">") . "\n";
            }
        } elseif ($val == "demographics") {
            if (!$csv) {
                echo "<hr />";
                echo "<div class='text demographics' id='DEM'>\n";
                print "<h4>" . xlt('Patient Data') . ":</h4>";
                echo "   <div class='table-responsive'><table class='table'>\n";
            } else {
                echo csvEscape("<BEGIN " . xlt("Patient Data") . ">") . "\n";
            }
            // printRecDataOne($patient_data_array, getRecPatientData ($pid), $N);
            $result1 = getPatientData($pid);
            $result2 = getEmployerData($pid);
            display_layout_rows('DEM', $result1, $result2, as_csv: $csv);
            if (!$csv) {
                echo "   </table></div>\n";
                echo "</div>\n";
            } else {
                echo csvEscape("<END " . xlt("Patient Data") . ">") . "\n";
            }
        } elseif ($val == "history") {
            if (AclMain::aclCheckCore('patients', 'med')) {
                if (!$csv) {
                    echo "<hr />";
                    echo "<div class='text history' id='HIS'>\n";
                    print "<h4>" . xlt('History Data') . ":</h4>";
                    // printRecDataOne($history_data_array, getRecHistoryData ($pid), $N);
                    echo "   <table>\n";
                } else {
                    echo csvEscape("<BEGIN " . xlt('History Data') . ">") . "\n";
                }
                $result1 = getHistoryData($pid);
                display_layout_rows('HIS', $result1, as_csv: $csv);
                if (!$csv) {
                    echo "   </table>\n";

                    echo "</div>";

                    // } elseif ($val == "employer") {
                    //   print "<br /><span class='bold'>".xl('Employer Data').":</span><br />";
                    //   printRecDataOne($employer_data_array, getRecEmployerData ($pid), $N);
                } else {
                    echo csvEscape("<END " . xlt('History Data') . ">") . "\n";
                }
            }
        } elseif ($val == "insurance") {
            if (!$csv) {
                echo "<hr />";
                echo "<div class='text insurance'>";
                echo "<h4>" . xlt('Insurance Data') . ":</h4>";
                print "<br /><span class='font-weight-bold'>" . xlt('Primary Insurance Data') . ":</span><br />";
                printRecDataOne($insurance_data_array, getRecInsuranceData($pid, "primary"), $N);
                print "<span class='font-weight-bold'>" . xlt('Secondary Insurance Data') . ":</span><br />";
                printRecDataOne($insurance_data_array, getRecInsuranceData($pid, "secondary"), $N);
                print "<span class='font-weight-bold'>" . xlt('Tertiary Insurance Data') . ":</span><br />";
                printRecDataOne($insurance_data_array, getRecInsuranceData($pid, "tertiary"), $N);
                echo "</div>";
            } else {
                echo csvEscape("<BEGIN " . xlt("Insurance Data") . ">") . "\n";
                // CSV headers:
                $insurance_columns = [
                    "type", "provider", "plan_name", "policy_number", "group_number", "subscriber_fname", "subscriber_mname",
                    "subscriber_lname", "subscriber_relationship", "subscriber_ss", "subscriber_DOB", "subscriber_phone",
                    "subscriber_street", "subscriber_postal_code", "subscriber_city", "subscriber_state", "subscriber_country",
                    "subscriber_employer", "subscriber_employer_street", "subscriber_employer_city", "subscriber_employer_postal_code",
                    "subscriber_employer_state", "subscriber_employer_country"
                ];
                $insurance_columns_escaped = $insurance_columns;
                for ($col = 0; $col < count($insurance_columns_escaped); $col++) {
                    switch ($insurance_columns_escaped[$col]) {
                    case "subscriber_lname":
                        $insurance_columns_escaped[$col] = "Subscriber Last Name"; 
                        break;
                    case "subscriber_mname":
                        $insurance_columns_escaped[$col] = "Subscriber Middle Name"; 
                        break;
                    case "subscriber_fname":
                        $insurance_columns_escaped[$col] = "Subscriber First Name"; 
                        break;
                    case "subscriber_ss":
                        $insurance_columns_escaped[$col] = "Subscriber SS"; 
                        break;
                    case "subscriber_DOB":
                        $insurance_columns_escaped[$col] = "Subscriber Date of Birth"; 
                        break;
                    case "subscriber_street":
                        $insurance_columns_escaped[$col] = "Subscriber Address"; 
                        break;
                    case "subscriber_postal_code":
                        $insurance_columns_escaped[$col] = "Subscriber Zip"; 
                        break;
                    case "subscriber_employer_postal_code":
                        $insurance_columns_escaped[$col] = "Subscriber Employer Zip"; 
                        break;
                    default:
                        $insurance_columns_escaped[$col] = ucwords(str_replace('_', ' ', $insurance_columns_escaped[$col]));
                    }
                    $insurance_columns_escaped[$col] = csvEscape(xlt($insurance_columns_escaped[$col]));
                }
                echo implode(',', $insurance_columns_escaped) . "\n";
                foreach (["primary", "secondary", "tertiary"] as $insurance_type) {
                    $insurance_object = getRecInsuranceData($pid, $insurance_type);
                    $fields_string = '';
                    foreach ($insurance_columns as $key) {
                        if ($fields_string != '') {
                            $fields_string .= ",";
                        }
                        if ($key == "provider") {
                            $key = "provider_name";
                        }
                        if ($key == "type") {
                            $fields_string .= csvEscape(ucwords($insurance_type));
                        } else if (isset($insurance_object[$key])) {
                            $fields_string .= csvEscape($insurance_object[$key][1]["value"]);
                        } else {
                            $fields_string .= '""';
                        }
                    }
                    echo $fields_string . "\n";
                }
                echo csvEscape("<END " . xlt("Insurance Data") . ">") . "\n";
            }
        } elseif ($val == "billing") {
            if (!$csv) {
                echo "<hr />";
                echo "<div class='text billing'>";
                print "<h4>" . xlt('Billing Information') . ":</h4>";
            } else {
                echo csvEscape("<BEGIN " . xlt('Billing Information') . ">") . "\n";
                // CSV headers:
                echo csvEscape(xlt("Date")) . ",";
                echo csvEscape(xlt("Code Type")) . ",";
                echo csvEscape(xlt("Code")) . ",";
                echo csvEscape(xlt("Code Text")) . ",";
                echo csvEscape(xlt("Modifier")) . "\n";
            }
            if (!empty($ar['newpatient']) && count($ar['newpatient']) > 0) {
                $billings = array();
                if (!$csv) {
                    echo "<div class='table-responsive'><table class='table'>";
                    echo "<tr><td class='font-weight-bold'>" . xlt('Code') . "</td><td class='font-weight-bold'>" . xlt('Fee') . "</td></tr>\n";
                }
                $total = 0.00;
                $copays = 0.00;
                foreach ($ar['newpatient'] as $be) {
                    $ta = explode(":", $be);
                    $billing = getPatientBillingEncounter($pid, $ta[1]);
                    $billings[] = $billing;
                    foreach ($billing as $b) {
                        if (!$csv) {
                            echo "<tr>\n";
                            echo "<td class='text'>";
                            echo text($b['code_type']) . ":\t" . text($b['code']) . "&nbsp;" . text($b['modifier']) . "&nbsp;&nbsp;&nbsp;" . text($b['code_text']) . "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
                            echo "</td>\n";
                            echo "<td class='text'>";
                            echo text(oeFormatMoney($b['fee']));
                            echo "</td>\n";
                            echo "</tr>\n";
                        } else {
                            echo csvEscape($b['date']) . "," . csvEscape($b['code_type']) . "," . csvEscape($b['code']) . "," . csvEscape($b['code_text']) . "," . csvEscape($b['modifier']) . "\n";
                        }
                        $total += $b['fee'];
                        if ($b['code_type'] == "COPAY") {
                            $copays += $b['fee'];
                        }
                    }
                }

                if (!$csv) {
                    echo "<tr><td>&nbsp;</td></tr>";
                    echo "<tr><td class='font-weight-bold'>" . xlt('Sub-Total') . "</td><td class='text'>" . text(oeFormatMoney($total + abs($copays))) . "</td></tr>";
                    echo "<tr><td class='font-weight-bold'>" . xlt('Paid') . "</td><td class='text'>" . text(oeFormatMoney(abs($copays))) . "</td></tr>";
                    echo "<tr><td class='font-weight-bold'>" . xlt('Total') . "</td><td class='text'>" . text(oeFormatMoney($total)) . "</td></tr>";
                    echo "</table></div>";
                    echo "<pre>";
                    //print_r($billings);
                    echo "</pre>";
                }
            } else {
                printPatientBilling($pid, as_csv: $csv);
            }

            if (!$csv) {
                echo "</div>\n"; // end of billing DIV
            } else {
                echo csvEscape("<END " . xlt('Billing Information') . ">") . "\n";
            }
        } elseif ($val == "immunizations") {
            if (AclMain::aclCheckCore('patients', 'med')) {
                if (!$csv) {
                    echo "<hr />";
                    echo "<div class='text immunizations'>\n";
                    print "<h4>" . xlt('Patient Immunization') . ":</h4>";
                } else {
                    echo csvEscape("<BEGIN " . xlt('Patient Immunization') . ">") . "\n";
                    // CSV headers:
                    echo csvEscape(xlt("Date")) . ",";
                    echo csvEscape(xlt("Vaccine")) . ",";
                    echo csvEscape(xlt("Notes")) . "\n";
                }
                $sql = "select i1.immunization_id, i1.administered_date, substring(i1.note,1,20) as immunization_note, c.code_text_short " .
                    " from immunizations i1 " .
                    " left join code_types ct on ct.ct_key = 'CVX' " .
                    " left join codes c on c.code_type = ct.ct_id AND i1.cvx_code = c.code " .
                    " where i1.patient_id = ? and i1.added_erroneously = 0 " .
                    " order by administered_date desc";
                $result = sqlStatement($sql, array($pid));
                while ($row = sqlFetchArray($result)) {
                    // Figure out which name to use (ie. from cvx list or from the custom list)
                    if ($GLOBALS['use_custom_immun_list'] || empty($row['code_text_short'])) {
                        $vaccine_display = generate_display_field(array('data_type' => '1', 'list_id' => 'immunizations'), $row['immunization_id']);
                    } else {
                        $vaccine_display = xlt($row['code_text_short']);
                    }
                    if (!$csv) {
                        echo text($row['administered_date']) . " - " . $vaccine_display;
                        if ($row['immunization_note']) {
                            echo " - " . text($row['immunization_note']);
                        }
                        echo "<br />\n";
                    } else {
                        echo csvEscape($row['administered_date']) . "," . csvEscape($vaccine_display) . "," . csvEscape($row['immunization_note']) . "\n";
                    }
                }

                if (!$csv) {
                    echo "</div>\n";
                } else {
                    echo csvEscape("<END " . xlt('Patient Immunization') . ">") . "\n";
                }
            }

            // communication report
        } elseif ($val == "batchcom") {
            /**
             * CSV export incomplete
             */ 
            if (!$csv) {
                echo "<hr />";
                echo "<div class='text transactions'>\n";
                print "<h4>" . xlt('Patient Communication sent') . ":</h4>";
            } else {
                echo csvEscape("<BEGIN " . xlt('Patient Communication sent') . ">") . "\n";
                // CSV headers:
                echo csvEscape(xlt("Type")) . ",";
                echo csvEscape(xlt("Subject")) . ",";
                echo csvEscape(xlt("Date")) . ",";
                echo csvEscape(xlt("By")) . ",";
                echo csvEscape(xlt("Text")) . "\n";
            }
            $sql = "SELECT concat( 'Messsage Type: ', batchcom.msg_type, ', Message Subject: ', batchcom.msg_subject, ', Sent on:', batchcom.msg_date_sent ) AS batchcom_data, batchcom.msg_text, concat( users.fname, users.lname ) AS user_name FROM `batchcom` JOIN `users` ON users.id = batchcom.sent_by WHERE batchcom.patient_id=?";
            if ($csv) {
                $sql = "SELECT batchcom.msg_type AS msg_type, batchcom.msg_subject AS msg_subject, batchcom.msg_date_sent AS msg_date, batchcom.msg_text AS msg_txt, concat( users.fname, users.lname ) AS user_name FROM `batchcom` JOIN `users` ON users.id = batchcom.sent_by WHERE batchcom.patient_id=?";
            }
            // echo $sql;
            $result = sqlStatement($sql, array($pid));
            while ($row = sqlFetchArray($result)) {
                if (!$csv) {
                    echo text($row['batchcom_data']) . ", By: " . text($row['user_name']) . "<br />Text:<br /> " . text($row['msg_txt']) . "<br />\n";
                } else {
                    echo csvEscape($row['msg_type']) . "," . csvEscape($row['msg_subject']) . "," . csvEscape($row['msg_date']) . "," . csvEscape($row['user_name']) . "," . csvEscape($row['msg_txt']) . "\n";
                }
            }

            if (!$csv) {
                echo "</div>\n";
            } else {
                echo csvEscape("<END " . xlt('Patient Communication sent') . ">") . "\n";
            }
        } elseif ($val == "notes") {
            if (!$csv) {
                echo "<hr />";
                echo "<div class='text notes'>\n";
                print "<h4>" . xlt('Patient Notes') . ":</h4>";
            } else {
                echo csvEscape("<BEGIN " . xlt('Patient Notes') . ">") . "\n";
                // CSV headers:
                echo csvEscape(xlt("Date")) . ",";
                echo csvEscape(xlt("From")) . ",";
                echo csvEscape(xlt("To")) . ",";
                echo csvEscape(xlt("Message")) . "\n";
            }
            printPatientNotes($pid, as_csv: $csv);
            if (!$csv) {
                echo "</div>";
            } else {
                echo csvEscape("<END " . xlt('Patient Notes') . ">") . "\n";
            }
        } elseif ($val == "transactions") {
            if (!$csv) {
                echo "<hr />";
                echo "<div class='text transactions'>\n";
                print "<h4>" . xlt('Patient Transactions') . ":</h4>";
            } else {
                echo csvEscape("<BEGIN " . xlt('Patient Transactions') . ">") . "\n";
            }
            printPatientTransactions($pid, as_csv: $csv);
            if (!$csv) {
                echo "</div>";
            } else {
                echo csvEscape("<END " . xlt('Patient Transactions') . ">") . "\n";
            }
        }
    } else {
        // Documents is an array of checkboxes whose values are document IDs.
        //
        if ($key == "documents") {
            /**
             * CSV export incomplete
             */ 
            if (!$csv) {
                echo "<hr />";
                echo "<div class='text documents'>";
            } else {
                echo csvEscape("<BEGIN " . xlt('Document')) . "\n";
            }
            foreach ($val as $valkey => $valvalue) {
                $document_id = $valvalue;
                if (!is_numeric($document_id)) {
                    continue;
                }

                $d = new Document($document_id);
                $fname = basename($d->get_name());
                //  Extract the extension by the mime/type and not the file name extension
                // -There is an exception. Need to manually see if it a pdf since
                //  the image_type_to_extension() is not working to identify pdf.
                $extension = strtolower(substr($fname, strrpos($fname, ".")));
                if ($extension != '.pdf') { // Will print pdf header within pdf import
                    echo "<h5>" . xlt('Document') . " '" . text($fname) . "-" . text($d->get_id()) . "'</h5>";
                } else if ($csv) {
                    echo csvEscape("<BEGIN " . text($fname) . "-" . text($d->get_id()) . ">") . "\n";
                }

                if (!$csv) {
                    $notes = $d->get_notes();
                    if (!empty($notes)) {
                        echo "<div class='table-responsive'><table class='table'>";
                    }

                    foreach ($notes as $note) {
                        echo '<tr>';
                        echo '<td>' . xlt('Note') . ' #' . text($note->get_id()) . '</td>';
                        echo '</tr>';
                        echo '<tr>';
                        echo '<td>' . xlt('Date') . ': ' . text(oeFormatShortDate($note->get_date())) . '</td>';
                        echo '</tr>';
                        echo '<tr>';
                        echo '<td>' . text($note->get_note()) . '<br /><br /></td>';
                        echo '</tr>';
                    }

                    if (!empty($notes)) {
                        echo "</table></div>";
                    }

                    // adding support for .txt MDM-TXA interface/orders/receive_hl7_results.inc.php
                    if ($extension != (".pdf" || ".txt")) {
                        $tempCDoc = new C_Document();
                        $tempFile = $tempCDoc->retrieve_action($d->get_foreign_id(), $document_id, false, true, true, true);
                        // tmp file in temporary_files_dir
                        $tempFileName = tempnam($GLOBALS['temporary_files_dir'], "oer");
                        file_put_contents($tempFileName, $tempFile);
                        $image_data = getimagesize($tempFileName);
                        $extension = image_type_to_extension($image_data[2]);
                        unlink($tempFileName);
                    }

                    if ($extension == ".png" || $extension == ".jpg" || $extension == ".jpeg" || $extension == ".gif") {
                        if ($PDF_OUTPUT) {
                            // OK to link to the image file because it will be accessed by the
                            // mPDF parser and not the browser.
                            $tempDocC = new C_Document();
                            $fileTemp = $tempDocC->retrieve_action($d->get_foreign_id(), $document_id, false, true, true, true);
                            // tmp file in ../documents/temp since need to be available via webroot
                            $from_file_tmp_web_name = tempnam($GLOBALS['OE_SITE_DIR'] . '/documents/temp', "oer");
                            file_put_contents($from_file_tmp_web_name, $fileTemp);
                            echo "<img src='$from_file_tmp_web_name'";
                            // Flag images with excessive width for possible stylesheet action.
                            $asize = getimagesize($from_file_tmp_web_name);
                            if ($asize[0] > 750) {
                                echo " class='bigimage'";
                            }
                            $tmp_files_remove[] = $from_file_tmp_web_name;
                            echo " /><br /><br />";
                        } else {
                            echo "<img src='" . $GLOBALS['webroot'] .
                                "/controller.php?document&retrieve&patient_id=&document_id=" .
                                attr_url($document_id) . "&as_file=false&original_file=true&disable_exit=false&show_original=true'><br /><br />";
                        }
                    } else {
                        // Most clinic documents are expected to be PDFs, and in that happy case
                        // we can avoid the lengthy image conversion process.
                        if ($PDF_OUTPUT && $extension == ".pdf") {
                            echo "</div></div>\n"; // HTML to PDF conversion will fail if there are open tags.
                            $content = getContent();
                            $pdf->writeHTML($content); // catch up with buffer.
                            $err = '';
                            try {
                                // below header isn't being used. missed maybe!
                                $pg_header = "<span>" . xlt('Document') . " " . text($fname) . "-" . text($d->get_id()) . "</span>";
                                $tempDocC = new C_Document();
                                $pdfTemp = $tempDocC->retrieve_action($d->get_foreign_id(), $document_id, false, true, true, true);
                                // tmp file in temporary_files_dir
                                $from_file_tmp_name = tempnam($GLOBALS['temporary_files_dir'], "oer");
                                file_put_contents($from_file_tmp_name, $pdfTemp);

                                $pagecount = $pdf->setSourceFile($from_file_tmp_name);
                                for ($i = 0; $i < $pagecount; ++$i) {
                                    $pdf->AddPage();
                                    $itpl = $pdf->importPage($i + 1);
                                    $pdf->useTemplate($itpl);
                                }
                            } catch (Exception $e) {
                                // chances are PDF is > v1.4 and compression level not supported.
                                // regardless, we're here so lets dispose in different way.
                                //
                                unlink($from_file_tmp_name);
                                $archive_name = ($GLOBALS['temporary_files_dir'] . '/' . report_basename($pid)['base'] . ".zip");
                                $rtn = zip_content(basename($d->url), $archive_name, $pdfTemp);
                                $err = "<span>" . xlt('PDF Document Parse Error and not included. Check if included in archive.') . " : " . text($fname) . "</span>";
                                $pdf->writeHTML($err);
                                $staged_docs[] = array('path' => $d->url, 'fname' => $fname);
                            } finally {
                                unlink($from_file_tmp_name);
                                // Make sure whatever follows is on a new page. Maybe!
                                // okay if not a series of pdfs so if so need @todo
                                if (empty($err)) {
                                    $pdf->AddPage();
                                }
                                // Resume output buffering and the above-closed tags.
                                ob_start();
                                echo "<div><div class='text documents'>\n";
                            }
                        } elseif ($extension == ".txt") {
                            echo "<pre>";
                            $tempDocC = new C_Document();
                            $textTemp = $tempDocC->retrieve_action($d->get_foreign_id(), $document_id, false, true, true, true);
                            echo text($textTemp);
                            echo "</pre>";
                        } else {
                            if ($PDF_OUTPUT) {
                                // OK to link to the image file because it will be accessed by the mPDF parser and not the browser.
                                $tempDocC = new C_Document();
                                $fileTemp = $tempDocC->retrieve_action($d->get_foreign_id(), $document_id, false, false, true, true);
                                // tmp file in ../documents/temp since need to be available via webroot
                                $from_file_tmp_web_name = tempnam($GLOBALS['OE_SITE_DIR'] . '/documents/temp', "oer");
                                file_put_contents($from_file_tmp_web_name, $fileTemp);
                                echo "<img src='$from_file_tmp_web_name'><br /><br />";
                                $tmp_files_remove[] = $from_file_tmp_web_name;
                            } else {
                                if ($extension === '.pdf' || $extension === '.zip') {
                                    echo "<strong>" . xlt('Available Document') . ":</strong><em> " . text($fname) . "</em><br />";
                                } else {
                                    echo "<img src='" . $GLOBALS['webroot'] . "/controller.php?document&retrieve&patient_id=&document_id=" . attr_url($document_id) . "&as_file=false&original_file=false'><br /><br />";
                                }
                            }
                        }
                    }
                } else {
                    echo csvEscape("<END " . text($fname) . "-" . text($d->get_id()) . ">") . "\n";
                } // end if-else
            } // end Documents loop
            if (!$csv) {
                echo "</div>";
            } else {
                echo csvEscape("<END " . xlt('Document')) . "\n";
            }
        } elseif ($key == "procedures") { // Procedures is an array of checkboxes whose values are procedure order IDs.
            if ($auth_med) {
                if (!$csv) {
                    echo "<hr />";
                    echo "<div class='text documents'>";
                } else {
                    echo csvEscape("<BEGIN " . xlt('Procedure Order') . ">") . "\n";
                }
                foreach ($val as $valkey => $poid) {
                    if (empty($GLOBALS['esign_report_show_only_signed'])) {
                        if (!$csv) {
                            echo '<h4>' . xlt('Procedure Order') . ':</h4>';
                            echo "<br />\n";
                        } else {
                            echo csvEscape("<BEGIN " . xlt('Procedure Order') . " " . $poid . ">") . "\n";
                        }
                        generate_order_report($poid, false, genstyles: !$csv ? !$PDF_OUTPUT : false, as_csv: $csv);
                        if (!$csv) {
                            echo "<br />\n";
                        } else {
                            echo csvEscape("<END " . xlt('Procedure Order') . " " . $poid . ">") . "\n";
                        }
                    }
                }
                if (!$csv) {
                    echo "</div>";
                } else {
                    echo csvEscape("<END " . xlt('Procedure Order') . ">") . "\n";
                }
            }
        } elseif (strpos($key, "issue_") === 0) {
            // display patient Issues
            if ($first_issue) {
                $prevIssueType = 'asdf1234!@#$'; // random junk so as to not match anything
                $first_issue = 0;
                if (!$csv) {
                    echo "<hr />";
                    echo "<h4>" . xlt("Issues") . "</h4>";
                }
            }

            if ($csv) {
                echo csvEscape("<BEGIN Issue>") . "\n";
                // CSV headers:
                echo csvEscape(xlt('Type')) . ",";
                echo csvEscape(xlt('Title')) . ",";
                echo csvEscape(xlt("Comments")) . ",";
                echo csvEscape(xlt("Drug Dosage Instructions")) . ",";
                echo csvEscape(xlt('Name (GMDN PT Name)')) . ",";
                echo csvEscape(xlt('Description')) . ",";
                echo csvEscape(xlt('Brand Name')) . ",";
                echo csvEscape(xlt('Company Name')) . ",";
                echo csvEscape(xlt('Version/Model Number')) . ",";
                echo csvEscape(xlt('DI (Device Identifier)')) . ",";
                echo csvEscape(xlt('Serial Number')) . ",";
                echo csvEscape(xlt('Lot Number')) . ",";
                echo csvEscape(xlt('Donation ID')) . ",";
                echo csvEscape(xlt('Expiration Date')) . ",";
                echo csvEscape(xlt('Manufacturing Date')) . ",";
                echo csvEscape(xlt('MRI Safety Status')) . ",";
                echo csvEscape(xlt('This device is required to be labeled as containing natural rubber latex or dry natural rubber.')) . ",";
                echo csvEscape(xlt('This device is labeled as a Human Cell, Tissue or Cellular or Tissue-Based Product (HCT/P).')) . ",";
                echo csvEscape(xlt('Issuing Agency')) . ",";
                echo csvEscape(xlt('Assigning Authority')) . ",";
                echo csvEscape(xlt('UDI (Unique Device Identifier)')) . "\n";
            }

            preg_match('/^(.*)_(\d+)$/', $key, $res);
            $rowid = $res[2];
            $irow = sqlQuery(
                "SELECT lists.type, lists.title, lists.comments, lists.diagnosis, " .
                "lists.udi_data, medications.drug_dosage_instructions FROM lists LEFT JOIN " .
                "( SELECT id AS lists_medication_id, list_id, drug_dosage_instructions " .
                "FROM lists_medication ) medications ON medications.list_id = id " .
                "WHERE id = ?", array($rowid)
            );
            $diagnosis = $irow['diagnosis'];
            if ($prevIssueType != $irow['type']) {
                // output a header for each Issue Type we encounter
                $disptype = $ISSUE_TYPES[$irow['type']][0];
                if (!$csv) {
                    echo "<div class='issue_type font-weight-bold'><h5>" . text($disptype) . ":</h5></div>\n";
                } else {
                    echo csvEscape(text($disptype)) . ",";
                }
                $prevIssueType = $irow['type'];
            }

            if (!$csv) {
                echo "<div class='text issue'>";
            }
            if ($prevIssueType == "medical_device") {
                if (!$csv) {
                    echo "<span class='issue_title'><span class='font-weight-bold'>" . xlt('Title') . ": </span>" . text($irow['title']) . "</span><br>";
                    echo "<span class='issue_title'>" . (new MedicalDevice($irow['udi_data']))->fullOutputHtml() . "</span>";
                    echo "<span class='issue_comments'> " . text($irow['comments']) . "</span><br><br>\n";
                } else {
                    echo csvEscape($irow['title']) . ",";
                    echo csvEscape($irow['comments']);
                    if ($irow['udi_data'] != '') {
                        $medical_device = json_decode($irow['udi_data'], true);
                        echo ',"",' . csvEscape($medical_device['deviceName']) . ",";
                        echo csvEscape($medical_device['deviceDescription']) . ",";
                        echo csvEscape($medical_device['brandName']) . ",";
                        echo csvEscape($medical_device['companyName']) . ",";
                        echo csvEscape($medical_device['versionModelNumber']) . ",";
                        echo csvEscape($medical_device['di']) . ",";
                        echo csvEscape($medical_device['serialNumber']) . ",";
                        echo csvEscape($medical_device['lotNumber']) . ",";
                        echo csvEscape($medical_device['donationId']) . ",";
                        echo csvEscape($medical_device['expirationDate']) . ",";
                        echo csvEscape($medical_device['manufacturingDate']) . ",";
                        echo csvEscape($medical_device['MRISafetyStatus']) . ",";
                        echo csvEscape($medical_device['labeledContainsNRL']) . ",";
                        echo csvEscape($medical_device['deviceHCTP']) . ",";
                        echo csvEscape($medical_device['issuingAgency']) . ",";
                        echo csvEscape($medical_device['udi']);
                    }
                    echo "\n";
                }
            } else {
                if (!$csv) {
                    echo "<span class='issue_title font-weight-bold'>" . text($irow['title']) . ":</span>";
                    echo "<span class='issue_comments'> " . text($irow['comments']) . "</span>\n";
                    if ($prevIssueType == "medication") {
                        echo "<span class='issue_dosage_instructions'> " . text($irow['drug_dosage_instructions']) . "</span>\n";
                    }
                } else {
                    echo csvEscape($irow['title']) . "," . csvEscape($irow['comments']);
                    if ($prevIssueType == "medication") {
                        echo "," . csvEscape($irow['drug_dosage_instructions']);
                    }
                    echo "\n";
                }
            }

            // Show issue's chief diagnosis and its description:
            if ($diagnosis) {
                if (!$csv) {
                    echo "<div class='text issue_diag'>";
                    echo "[" . xlt('Diagnosis') . "]<br />";
                } else {
                    echo csvEscape("<BEGIN " . xlt('Diagnosis') . ">") . "\n";
                    // CSV headers:
                    echo csvEscape(xlt("Code")) . "," . csvEscape(xlt("Description")) . "\n";
                }
                $dcodes = explode(";", $diagnosis);
                foreach ($dcodes as $dcode) {
                    if (!$csv) {
                        echo "<span class='italic'>" . text($dcode) . "</span>: ";
                        echo text(lookup_code_descriptions($dcode)) . "<br />\n";
                    } else {
                        echo csvEscape($dcode) . "," . csvEscape(lookup_code_descriptions($dcode)) . "\n";
                    }
                }

                //echo $diagnosis." -- ".lookup_code_descriptions($diagnosis)."\n";
                if (!$csv) {
                    echo "</div>";
                } else {
                    echo csvEscape("<END " . xlt('Diagnosis') . ">") . "\n";
                }
            }

            // Supplemental data for GCAC or Contraception issues.
            if ($irow['type'] == 'ippf_gcac') {
                echo "   <div class='table-responsive'><table class='table'>\n";
                display_layout_rows('GCA', sqlQuery("SELECT * FROM lists_ippf_gcac WHERE id = ?", array($rowid)));
                echo "   </table></div>\n";
            } elseif ($irow['type'] == 'contraceptive') {
                echo "   <div class='table-responsive'><table class='table'>\n";
                display_layout_rows('CON', sqlQuery("SELECT * FROM lists_ippf_con WHERE id = ?", array($rowid)));
                echo "   </table></div>\n";
            }

            if (!$csv) {
                echo "</div>\n"; //end the issue DIV
            } else {
                echo csvEscape("<END Issue>") . "\n";
            }
        } else {
            // we have an "encounter form" form field whose name is like
            // dirname_formid, with a value which is the encounter ID.
            //
            // display encounter forms, encoded as a POST variable
            // in the format: <formdirname_formid>=<encounterID>

            if (($auth_notes_a || $auth_notes || $auth_coding_a || $auth_coding || $auth_med || $auth_relaxed)) {
                $form_encounter = $val;
                preg_match('/^(.*)_(\d+)$/', $key, $res);
                $form_id = $res[2];
                $formres = getFormNameByFormdirAndFormid($res[1], $form_id);
                $dateres = getEncounterDateByEncounter($form_encounter);
                $formId = getFormIdByFormdirAndFormid($res[1], $form_id);

                $encounter_classes = null;
                $encounter_title = null;
                if ($res[1] == 'newpatient') {
                    $encounter_classes = "text encounter";
                    $encounter_title = xlt($formres["form_name"]);
                } else {
                    $encounter_classes = "text encounter_form";
                    $encounter_title = text(xl_form_title($formres["form_name"]));
                }
                if (!$csv) {
                    echo "<div class='" . $encounter_classes . "'>\n";
                    echo "<h4>" . $encounter_title . "</h4>";

                    // show the encounter's date
                    echo "(" . text(oeFormatSDFT(strtotime($dateres["date"]))) . ") ";
                    if ($res[1] == 'newpatient') {
                        // display the provider info
                        echo ' ' . xlt('Provider') . ': ' . text(getProviderName(getProviderIdOfEncounter($form_encounter)));
                    }

                    echo "<br />\n";
                } else {
                    echo csvEscape("<BEGIN Encounter " . $dateres["date"] . " " . getProviderName(getProviderIdOfEncounter($form_encounter)) . "," . $encounter_title . ">") . "\n";
                }


                // call the report function for the form
                if (!$csv) { ?>
                            <div name="search_div" id="search_div_<?php echo attr($form_id) ?>_<?php echo attr($res[1]) ?>" class="report_search_div class_<?php echo attr($res[1]); ?>">
                <?php }
                if (!empty($res[1])) {
                    $esign = $esignApi->createFormESign($formId, $res[1], $form_encounter);
                    if (($esign->isSigned('report') && !empty($GLOBALS['esign_report_show_only_signed'])) || empty($GLOBALS['esign_report_show_only_signed'])) {
                        if (substr($res[1], 0, 3) == 'LBF') {
                            lbf_report($pid, $form_encounter, $N, $form_id, $res[1], as_csv: $csv);
                        } else {
                            // Check to see if function has as_csv parameter, and if not, warn of incompatibility
                            $user_func_args = ['pid' => $pid, 'encounter' => $form_encounter, 'cols' => $N, 'id' => $form_id];
                            $user_func = new ReflectionFunction($res[1] . "_report");
                            foreach ($user_func->getParameters() as $user_func_param) {
                                if ($user_func_param->name == 'as_csv') {
                                    $user_func_args['as_csv'] = $csv;
                                }
                            }
                            if (!isset($user_func_args['as_csv'])) {
                                echo csvEscape("<! WARNING: function " . $res[1] . "_report DOES NOT SUPPORT CSV EXPORT !>") . "\n";
                            }
                            call_user_func_array($res[1] . "_report", $user_func_args);
                        }
                    } else if (!$csv) {
                        echo "<h6>" . xlt("Not signed.") . "</h6>";
                    }
                    if ($esign->isLogViewable("report")) {
                        $esign->renderLog();
                    }
                }
                if (!$csv) { ?>

                            </div>
                <?php }

                if ($res[1] == 'newpatient' && !$csv) {
                    // display billing info
                    $bres = sqlStatement(
                        "SELECT b.date, b.code, b.code_text, b.modifier " .
                        "FROM billing AS b, code_types AS ct WHERE " .
                        "b.pid = ? AND " .
                        "b.encounter = ? AND " .
                        "b.activity = 1 AND " .
                        "b.code_type = ct.ct_key AND " .
                        "ct.ct_diag = 0 " .
                        "ORDER BY b.date",
                        array($pid, $form_encounter)
                    );
                    while ($brow = sqlFetchArray($bres)) {
                        echo "<div class='font-weight-bold d-inline-block'>&nbsp;" . xlt('Procedure') . ": </div><div class='text d-inline-block'>" .
                            text($brow['code']) . ":" . text($brow['modifier']) . " " . text($brow['code_text']) . "</div><br />\n";
                    }
                }

                if (!$csv) {
                    print "</div>";
                } else {
                    echo csvEscape("<END Encounter " . $dateres["date"] . " " . getProviderName(getProviderIdOfEncounter($form_encounter)) . "," . $encounter_title . ">") . "\n";
                }
            } // end auth-check for encounter forms
        } // end if('issue_')... else...
    } // end if('include_')... else...
} // end $ar loop

if (!$csv) {
    if ($printable && !$PDF_OUTPUT) {// Patched out of pdf 04/20/2017 sjpadgett
        echo "<br /><br />" . xlt('Signature') . ": _______________________________<br />";
    }
    ?>

        </div> <!-- end of report_custom DIV -->
    </div>
<?php }

if ($PDF_OUTPUT) {
    $content = getContent();
    $ptd = report_basename($pid);
    $fn = $ptd['base'] . ".pdf";
    $pdf->SetTitle(ucfirst($ptd['fname']) . ' ' . $ptd['lname'] . ' ' . xl('Id') . ':' . $pid . ' ' . xl('Report'));
    $isit_utf8 = preg_match('//u', $content); // quick check for invalid encoding
    if (!$isit_utf8) {
        if (function_exists('iconv')) { // if we can lets save the report
            $content = iconv("UTF-8", "UTF-8//IGNORE", $content);
        } else { // no sense going on.
            $die_str = xlt("Failed UTF8 encoding check! Could not automatically fix.");
            die($die_str);
        }
    }

    try {
        $pdf->writeHTML($content); // convert html
    } catch (MpdfException $exception) {
        die(text($exception));
    }

    if ($PDF_OUTPUT == 1) {
        try {
            if ($PDF_FAX === 1) {
                $fax_pdf = $pdf->Output($fn, 'S');
                $tmp_file = $GLOBALS['temporary_files_dir'] . '/' . $fn; // is deleted in sendFax...
                file_put_contents($tmp_file, $fax_pdf);
                echo $tmp_file;
                exit();
            } else {
                if (!empty($archive_name) && sizeof($staged_docs) > 0) {
                    $rtn = zip_content(basename($fn), $archive_name, $pdf->Output($fn, 'S'));
                    header('Content-Description: File Transfer');
                    header('Content-Transfer-Encoding: binary');
                    header('Expires: 0');
                    header("Cache-control: private");
                    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                    header("Content-Type: application/zip; charset=utf-8");
                    header("Content-Length: " . filesize($archive_name));
                    header('Content-Disposition: attachment; filename="' . basename($archive_name) . '"');

                    ob_end_clean();
                    @readfile($archive_name) or error_log("Archive temp file not found: " . $archive_name);

                    unlink($archive_name);
                } else {
                    $pdf->Output($fn, $GLOBALS['pdf_output']); // D = Download, I = Inline
                }
            }
        } catch (MpdfException $exception) {
            die(text($exception));
        }
    } else {
        // This is the case of writing the PDF as a message to the CMS portal.
        $ptdata = getPatientData($pid, 'cmsportal_login');
        $contents = $pdf->Output('', true);
        echo "<html><head>\n";
        Header::setupHeader();
        echo "</head><body>\n";
        $result = cms_portal_call(
            array(
            'action' => 'putmessage',
            'user' => $ptdata['cmsportal_login'],
            'title' => xl('Your Clinical Report'),
            'message' => xl('Please see the attached PDF.'),
            'filename' => 'report.pdf',
            'mimetype' => 'application/pdf',
            'contents' => base64_encode($contents)
            )
        );
        if ($result['errmsg']) {
            die(text($result['errmsg']));
        }

        echo "<p class='mt-3'>" . xlt('Report has been sent to the patient.') . "</p>\n";
        echo "</body></html>\n";
    }
    foreach ($tmp_files_remove as $tmp_file) {
        // Remove the tmp files that were created
        unlink($tmp_file);
    }
} else if (!$csv) {
    if (!$printable) { ?>
        <script src="<?php echo $GLOBALS['web_root'] ?>/interface/patient_file/report/custom_report.js?v=<?php echo $v_js_includes; ?>"></script>
        <script>
            const searchBarHeight = document.querySelectorAll('.report_search_bar')[0].clientHeight;
            document.getElementById('backLink').style.marginTop = `${searchBarHeight}px`;
        </script>
    <?php } ?>

</body>
</html>
<?php } ?>
