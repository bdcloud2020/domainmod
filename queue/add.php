<?php
/**
 * /queue/add.php
 *
 * This file is part of DomainMOD, an open source domain and internet asset manager.
 * Copyright (c) 2010-2016 Greg Chetcuti <greg@chetcuti.com>
 *
 * Project: http://domainmod.org   Author: http://chetcuti.com
 *
 * DomainMOD is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
 * version.
 *
 * DomainMOD is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with DomainMOD. If not, see
 * http://www.gnu.org/licenses/.
 *
 */
?>
<?php
include("../_includes/start-session.inc.php");
include("../_includes/init.inc.php");

require_once(DIR_ROOT . "classes/Autoloader.php");
spl_autoload_register('DomainMOD\Autoloader::classAutoloader');

$system = new DomainMOD\System();
$error = new DomainMOD\Error();
$layout = new DomainMOD\Layout();
$domain = new DomainMOD\Domain();
$time = new DomainMOD\Time();
$form = new DomainMOD\Form();

include(DIR_INC . "head.inc.php");
include(DIR_INC . "config.inc.php");
include(DIR_INC . "software.inc.php");
include(DIR_INC . "settings/queue-add.inc.php");
include(DIR_INC . "database.inc.php");

$system->authCheck();
$system->readOnlyCheck($_SERVER['HTTP_REFERER']);

$new_raid = $_REQUEST['new_raid'];
$new_data = $_POST['new_data'];

if ($new_raid != '' ) {

    $query = "SELECT apir.name, apir.req_account_username, apir.req_account_password, apir.req_reseller_id,
                  apir.req_api_app_name, apir.req_api_key, apir.req_api_secret, apir.req_ip_address, apir.lists_domains,
                  apir.ret_expiry_date, apir.ret_dns_servers, apir.ret_privacy_status, apir.ret_autorenewal_status,
                  apir.notes, ra.username, ra.password, ra.reseller_id, ra.api_app_name, ra.api_key, ra.api_secret,
                  ra.api_ip_id
              FROM registrar_accounts AS ra, registrars AS r, api_registrars AS apir
              WHERE ra.registrar_id = r.id
                AND r.api_registrar_id = apir.id
                AND ra.id = ?";
    $q = $conn->stmt_init();
    
    if ($q->prepare($query)) {
    
        $q->bind_param('i', $new_raid);
        $q->execute();
        $q->store_result();
        $q->bind_result($api_registrar_name, $req_account_username, $req_account_password, $req_reseller_id,
                        $req_api_app_name, $req_api_key, $req_api_secret, $req_ip_address, $lists_domains,
                        $ret_expiry_date, $ret_dns_servers, $ret_privacy_status, $ret_autorenewal_status,
                        $registrar_notes, $account_username, $account_password, $reseller_id, $api_app_name, $api_key,
                        $api_secret, $api_ip_id);
        $q->fetch();
        $q->close();
    
    } else $error->outputSqlError($conn, "ERROR");

}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $format = new DomainMOD\Format();
    $new_data = $format->stripSpacing($new_data);

    // If the registrar has the ability to retrieve the list of domains
    if ($lists_domains == '1' && $new_data == '') {

        if ($new_raid == '') {

            if ($new_raid == '') $_SESSION['s_message_danger'] .= "Please choose the registrar account<BR>";

        } else {
            
            $sql = "SELECT ra.owner_id, ra.registrar_id, r.api_registrar_id
                    FROM registrar_accounts AS ra, registrars AS r
                    WHERE ra.registrar_id = r.id
                      AND ra.id = '" . $new_raid . "'";
            $result = mysqli_query($connection, $sql);

            while ($row = mysqli_fetch_object($result)) {
                $temp_owner_id = $row->owner_id;
                $temp_registrar_id = $row->registrar_id;
                $temp_api_registrar_id = $row->api_registrar_id;
            }

            $query = "INSERT INTO domain_queue_list
                      (api_registrar_id, owner_id, registrar_id, account_id, created_by, insert_time)
                      VALUES
                      (?, ?, ?, ?, ?, ?)";
            $q = $conn->stmt_init();

            if ($q->prepare($query)) {

                $timestamp = $time->stamp();

                $q->bind_param('iiiiis', $temp_api_registrar_id, $temp_owner_id, $temp_registrar_id, $new_raid, $_SESSION['s_user_id'], $timestamp);
                $q->execute() or $error->outputSqlError($conn, "Couldn't add registrar account to list queue");
                $q->close();

            } else $error->outputSqlError($conn, "ERROR");

            $_SESSION['s_domains_in_list_queue'] = '1';

            $_SESSION['s_message_success'] .= "Registrar Account Added To Domain List Queue<BR>";

            header("Location: index.php");
            exit;

        }

    } else { // If the registrar's API DOES NOT have the ability to retrieve the list of domains, or if there's a
             // problem with he automatic import, use the list supplied

        // check to make sure that the registrar associated with the account has API support
        $sql = "SELECT ra.id, ra.registrar_id
                FROM registrar_accounts AS ra, registrars AS r, api_registrars AS ar
                WHERE ra.registrar_id = r.id
                  AND r.api_registrar_id = ar.id
                  AND ra.id = '" . $new_raid . "'";
        $result = mysqli_query($connection, $sql);

        if (mysqli_num_rows($result) == 0) {

            $sql_temp = "SELECT registrar_id
                         FROM registrar_accounts
                         WHERE id = '" . $new_raid . "'";
            $result_temp = mysqli_query($connection, $sql_temp);

            while ($row_temp = mysqli_fetch_object($result_temp)) {

                $temp_registrar_id = $row_temp->registrar_id;

            }

            $has_api_support = '0';

        } else {

            $has_api_support = '1';

        }

        if ($new_raid == '' || $new_data == '' || $has_api_support != '1') {

            if ($has_api_support != '1' && $new_raid != '') {

                $_SESSION['s_message_danger'] .= "Either the registrar associated with this account doesn't have API support, or you haven't yet updated the registrar to indicate API support.<BR><BR>Please check the <a href='" . $web_root . "/assets/edit/registrar.php?rid=" . $temp_registrar_id . "'>registrar</a> and try again.";

            } else {

                if ($new_raid == '') $_SESSION['s_message_danger'] .= "Please choose the registrar account<BR>";
                if ($new_data == '') $_SESSION['s_message_danger'] .= "Enter the list of domains to add to the queue<BR>";

            }

        } else {

            $domain_list = array_unique(explode("\r\n", $new_data));

            list($invalid_to_display, $invalid_domains, $invalid_count, $temp_result_message) = $domain->findInvalidDomains($domain_list);

            if ($new_data == "" || $invalid_domains == 1) {

                if ($invalid_domains == 1) {

                    if ($invalid_count == 1) {

                        $_SESSION['s_message_danger'] .= "There is " . number_format($invalid_count) . " invalid domain on your list<BR><BR>" . $temp_result_message;

                    } else {

                        $_SESSION['s_message_danger'] .= "There are " . number_format($invalid_count) . " invalid domains on your list<BR><BR>" . $temp_result_message;

                        if (($invalid_count - $invalid_to_display) == 1) {

                            $_SESSION['s_message_danger'] .= "<BR>Plus " . number_format($invalid_count - $invalid_to_display) . " other<BR>";

                        } elseif (($invalid_count - $invalid_to_display) > 1) {

                            $_SESSION['s_message_danger'] .= "<BR>Plus " . number_format($invalid_count - $invalid_to_display) . " others<BR>";
                        }

                    }

                } else {

                    $_SESSION['s_message_danger'] .= "Enter the list of domains to add to the queue<BR>";

                }
                $submission_failed = 1;

            } else {

                $new_data_formatted = "'" . $new_data;
                $new_data_formatted = $new_data_formatted . "'";
                $new_data_formatted = preg_replace("/\r\n/", "','", $new_data_formatted);
                $new_data_formatted = str_replace(" ", "", $new_data_formatted);
                $new_data_formatted = trim($new_data_formatted);

                $date = new DomainMOD\Date();

                // Make sure the domains don't already exist in the domains table
                $domain_list = array_unique(explode("\r\n", $new_data));

                reset($domain_list);

                // cycle through domains here
                while (list($key, $new_domain) = each($domain_list)) {

                    $query = "SELECT domain
                              FROM domains
                              WHERE domain = ?";
                    $q = $conn->stmt_init();

                    if ($q->prepare($query)) {

                        $q->bind_param('s', $new_domain);
                        $q->execute();
                        $q->store_result();

                        if ($q->num_rows() > 0) {

                            $has_existing_domains = '1';

                        }

                    }

                }

                // Make sure the domains don't already exist in the domain queue
                $domain_list = array_unique(explode("\r\n", $new_data));

                reset($domain_list);

                // cycle through domains here
                while (list($key, $new_domain) = each($domain_list)) {

                    $query = "SELECT domain
                              FROM domain_queue
                              WHERE domain = ?";
                    $q = $conn->stmt_init();

                    if ($q->prepare($query)) {

                        $q->bind_param('s', $new_domain);
                        $q->execute();
                        $q->store_result();

                        if ($q->num_rows() > 0) {

                            $has_existing_domains_queue = '1';

                        }

                    }

                }

                if ($new_raid == "" || $new_raid == "0" || $has_existing_domains == '1' || $has_existing_domains_queue == '1') {

                    if ($has_existing_domains == '1') $_SESSION['s_message_danger'] .= "At least one of the domains you entered already exists in " . $software_title . ".<BR><BR>You should run the domain list through a Segment filter to determine which one(s).<BR><BR>";
                    if ($has_existing_domains_queue == '1') $_SESSION['s_message_danger'] .= "At least one of the domains you entered is already in the domain queue.<BR>";
                    if ($new_raid == "" || $new_raid == "0") $_SESSION['s_message_danger'] .= "Please choose the registrar account<BR>";

                    $submission_failed = 1;

                } else {

                    $sql = "SELECT ra.owner_id, ra.registrar_id, r.api_registrar_id
                            FROM registrar_accounts AS ra, registrars AS r
                            WHERE ra.registrar_id = r.id
                              AND ra.id = '" . $new_raid . "'";
                    $result = mysqli_query($connection, $sql);

                    while ($row = mysqli_fetch_object($result)) {
                        $temp_owner_id = $row->owner_id;
                        $temp_registrar_id = $row->registrar_id;
                        $temp_api_registrar_id = $row->api_registrar_id;
                    }

                    $domain_list = array_unique(explode("\r\n", $new_data));

                    reset($domain_list);

                    // cycle through domains here
                    while (list($key, $new_domain) = each($domain_list)) {

                        $domain_temp = new DomainMOD\Domain();
                        $new_tld = $domain_temp->getTld($new_domain);

                        $query = "INSERT INTO domain_queue
                                  (api_registrar_id, domain, owner_id, registrar_id, account_id, tld, created_by, insert_time)
                                  VALUES
                                  (?, ?, ?, ?, ?, ?, ?, ?)";
                        $q = $conn->stmt_init();

                        if ($q->prepare($query)) {

                            $timestamp = $time->stamp();

                            $q->bind_param('isiiisis', $temp_api_registrar_id, $new_domain, $temp_owner_id, $temp_registrar_id,
                                $new_raid, $new_tld, $_SESSION['s_user_id'], $timestamp);
                            $q->execute() or $error->outputSqlError($conn, "Couldn't add domains to queue");
                            $q->close();

                        } else $error->outputSqlError($conn, "ERROR");

                    } // finish cycling through domains here

                    $done = "1";
                    $new_data_unformatted = strtolower(preg_replace("/\r\n/", ", ", $new_data));

                    $_SESSION['s_domains_in_queue'] = '1';

                    $_SESSION['s_message_success'] .= "Domains Added To Queue<BR>";

                }

            }

        }

    }

}
?>
<?php include(DIR_INC . 'doctype.inc.php'); ?>
<html>
<head>
    <title><?php echo $system->pageTitle($software_title, $page_title); ?></title>
    <?php include(DIR_INC . "layout/head-tags.inc.php"); ?>
    <?php echo $layout->jumpMenu(); ?>
</head>
<body class="hold-transition skin-red sidebar-mini">
<?php include(DIR_INC . "layout/header.inc.php"); ?>
<?php
if ($done == "1") {

    if ($submission_failed != "1") { ?>

        <strong>The following domains were added to the queue:</strong><BR>
        <?php echo htmlentities($new_data_unformatted, ENT_QUOTES); ?><BR><BR><?php

    }

}
?>
<strong>Domain Queue & API Prerequisites</strong><BR>
Before you can add domains to DomainMOD using the Domain Queue you must first do the following:
<ol>
<li>Ensure that the registrar has an API and that your account has been granted access to it</li>
<li>Enable API Support on the <a href="<?php echo $web_root; ?>/assets/registrars.php">registrar asset</a></li>
<li>Save the required API credentials with the <a href="<?php echo $web_root; ?>/assets/registrar-accounts.php">registrar account asset</a></li>
</ol><?php

echo $form->showFormTop('');

echo $form->showDropdownTopJump('', '', '', '');

$sql_account = "SELECT ra.id, ra.username, o.name AS o_name, r.name AS r_name
                FROM registrar_accounts AS ra, owners AS o, registrars AS r
                WHERE ra.owner_id = o.id
                  AND ra.registrar_id = r.id
                  AND r.api_registrar_id != '0'
                ORDER BY r_name, o_name, ra.username";
$result_account = mysqli_query($connection, $sql_account) or $error->outputOldSqlError($connection);

echo $form->showDropdownOptionJump('add.php', '', 'Choose the Registrar Account to import', '');

while ($row_account = mysqli_fetch_object($result_account)) {

    echo $form->showDropdownOptionJump('add.php?new_raid=', $row_account->id, $row_account->r_name . ', ' . $row_account->o_name . ' (' . $row_account->username . ')', $new_raid);

}
echo $form->showDropdownBottom('');

if ($new_raid != '') { ?>

    <strong>API Requirements</strong><BR>
    <?php echo $api_registrar_name; ?> requires the following credentials in order to use their API. These credentials must to be saved with the <a href="<?php echo $web_root; ?>/assets/edit/registrar-account.php?raid=<?php echo $new_raid; ?>">registrar account asset</a>.

    <ul><?php

        $missing_text = ' (<a href="' . $web_root . '/assets/edit/registrar-account.php?raid=' . $new_raid . '"><span style="color: #a30000"><strong>missing</strong></span></a>)';
        $saved_text = ' (<span style="color: darkgreen"><strong>saved</strong></span>)';

        if ($req_account_username == '1') {
            echo '<li>Registrar Account Username';
            if ($account_username == '') { echo $missing_text; } else { echo $saved_text; }
            echo '</li>';
        }
        if ($req_account_password == '1') {
            echo '<li>Registrar Account Password';
            if ($account_password == '') { echo $missing_text; } else { echo $saved_text; }
            echo '</li>';
        }
        if ($req_reseller_id == '1') {
            echo '<li>Reseller ID';
            if ($reseller_id == '' || $reseller_id == '0') { echo $missing_text; } else { echo $saved_text; }
            echo '</li>';
        }
        if ($req_api_app_name == '1') {
            echo '<li>API Application Name';
            if ($api_app_name == '') { echo $missing_text; } else { echo $saved_text; }
            echo '</li>';
        }
        if ($req_api_key == '1') {
            echo '<li>API Key';
            if ($api_key == '') { echo $missing_text; } else { echo $saved_text; }
            echo '</li>';
        }
        if ($req_api_secret == '1') {
            echo '<li>API Secret';
            if ($api_secret == '') { echo $missing_text; } else { echo $saved_text; }
            echo '</li>';
        }
        if ($req_ip_address == '1') {
            echo '<li>Connecting IP Address';
            if ($api_ip_id == '0') { echo $missing_text; } else { echo $saved_text; }
            echo '</li>';
        } ?>
    </ul><?php

}

if ($registrar_notes != '') {
    
    echo '<strong>Registrar Notes</strong><BR>';
    echo $registrar_notes . "<BR><BR>";
    
}

if ($new_raid != '') {

    if ($lists_domains == '1') {

        echo '<strong>Domain List</strong><BR>';
        echo htmlentities($api_registrar_name) . '\'s API has a domain list feature, so you don\'t even have to supply a list of the domains you want to import, DomainMOD will retrieve them for you automatically. If for some reason you\'re having issues with the automatic import though, you can still manually paste a list of domains to import below.<BR><BR>';
        echo $form->showInputTextarea('new_data', '[OPTIONAL] Domains to add (one per line)', '', $new_data, '', '', '');

    } else {

        echo $form->showInputTextarea('new_data', 'Domains to add (one per line)', '', $new_data, '1', '', '');
    }

}

if ($new_raid != '') {

    echo $form->showSubmitButton('Add Domains', '', '');

}

echo $form->showInputHidden('new_raid', $new_raid);
echo $form->showFormBottom('');
?>
<?php include(DIR_INC . "layout/footer.inc.php"); ?>
</body>
</html>
