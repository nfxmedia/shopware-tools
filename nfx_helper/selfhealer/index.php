<?php

/**
 * nfx:MEDIA Self Healder
 *
 * @link http://www.nfxmedia.de
 * @copyright Copyright (c) 2014, nfx:MEDIA
 * @author nf, ma - info@nfxmedia.de;
 * @package nfxMEDIA
 * @subpackage Shopware Helper
 * @version 1.0.0 initial release of the plugin 
 * @version 1.0.1 improve logging 
 */
error_reporting(E_ALL);
DEFINE("LAST_RUN_FILE", "lastrun.txt");
DEFINE("LOG_FILE", "log<>.txt");
DEFINE("FROM_EMAIL", "support@nfxmedia.de");
DEFINE("TO_EMAIL", "cron_debug@nfxmedia.de"); // to show us, if there are real problems with one of our cronjobs. Feel free to replace it with yours
DEFINE("SUBJECT", "Cron Check Warning");

$db = include(realpath(dirname(__FILE__) . '/../../') . '/config.php');
$DB_HOST = $db["db"]["host"];
$DB_PORT = $db["db"]["port"];
$DB_USER = $db["db"]["username"];
$DB_PASSWORD = $db["db"]["password"];
$DB_DATABASE = $db["db"]["dbname"];

chmod(dirname(__FILE__), 0755);

//connect to database
echo "Connecting to database...<br /> ";

if($DB_PORT){
    $DB_HOST .= ":" . $DB_PORT;
}
//$con = mysql_connect($DB_HOST . ":" . $DB_PORT, $DB_USER, $DB_PASSWORD);
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASSWORD, $DB_DATABASE);

if ($conn->connect_errno) {
    die("Failed to connect to database: " . $conn->connect_error);
} else {
    echo "Database connection successfully established.<br />";
}

removeOldFiles();

// get the status of the cronjobs from previous run
$arr_last = array();
if (file_exists(dirname(__FILE__) . "/" . LAST_RUN_FILE)) {
    $text = file_get_contents(dirname(__FILE__) . "/" . LAST_RUN_FILE);
    $lines = explode("\r\n", $text);
    foreach ($lines as $line) {
        $arr_last[] = explode("\t", $line);
    }
}
// get the list of all installed cronjobs
$arr_msg = array();
$text_last = "";
$sql = "SELECT name, active, start, end, next, elementID FROM s_crontab WHERE name LIKE '%nfx%' OR action LIKE '%nfx%' OR name LIKE '%sagepay%'";
//mysql_select_db($DB_DATABASE, $con);

//$result = mysql_query($sql);
$result = $conn->query($sql);
$num_rows = $result->num_rows;

if($num_rows){
    $row = $result->fetch_assoc();
    //while ($row = mysql_fetch_assoc($result)) {
    while ($row) {
        $msg = "Name: " . $row["name"] . "; Active: " . $row["active"] . "; Start: " . $row["start"] . ";";
        $msg .= " End: " . $row["end"] . "; Next: " . $row["next"] . "; elementID: " . $row["elementID"] . ";";
        $text_last .= $row["name"] . "\t" . $row["active"] . "\t" . $row["start"] . "\t" . $row["end"] . "\t" . $row["next"] . "\t" . $row["elementID"] . "\r\n";
        logMessage(LOG_FILE, $msg);
        $active = $row["active"];
        if ($active) {
            if (!$row["end"]) {
                //check previous message
                foreach ($arr_last as $cron) {
                    if ($row["name"] == $cron[0]) {
                        //"end" is empty and the cron status is not changes since last run => there is something wrong
                        $active = !(($row["active"] == $cron[1]) && ($row["start"] == $cron[2]) && ($row["end"] == $cron[3]) &&
                                ($row["next"] == $cron[4]) && ($row["elementID"] == $cron[5]));                        
                        break;
                    }
                }
            }
        }
        if (!$active) {
            $sql = "UPDATE s_crontab
                    SET active = 1,
                            end = NOW(),
                            next = NOW()
                    WHERE name LIKE '" . $row["name"] . "'";
            //mysql_query($sql);
            $conn->query($sql);
            $arr_msg[] = $msg;
            logMessage(LOG_FILE, "--- reactivated");
        } else {
            logMessage(LOG_FILE, "--- ok");
        }
        $row = $result->fetch_assoc();
    }
}

logLastRun(LAST_RUN_FILE, $text_last);

if (count($arr_msg)) {
    if (TO_EMAIL) {
        echo "Send warning email ...<br>";
        sendEmail(FROM_EMAIL, TO_EMAIL, SUBJECT, $arr_msg);
    }
}

echo "END";

/**
 * log the actions
 * @param type $filename
 * @param type $msg
 */
function logMessage($filename, $msg) {
    try {
        $filename = str_replace("<>", date('Ymd', strtotime('Last Monday', time())), $filename);
        if ($fh = @fopen(dirname(__FILE__) . "/" . $filename, "a+")) {
            fputs($fh, date("Y-m-d H:i:s") . ": " . $msg . "\r\n");
        }

        @fclose($fh);
    } catch (Exception $ex) {
        
    }
    echo $msg . "<br>";
}

/**
 * log the last screenshot
 * @param type $filename
 * @param type $text_last
 */
function logLastRun($filename, $text_last) {
    try {
        if ($fh = @fopen(dirname(__FILE__) . "/" . $filename, "w")) {
            fputs($fh, $text_last);
        }

        @fclose($fh);
    } catch (Exception $ex) {
        
    }
}

/**
 * send email in case of reactivation
 * @param type $sender
 * @param type $to
 * @param type $subject
 * @param type $arr_msg
 */
function sendEmail($sender, $to, $subject, $arr_msg) {
    $message = "<head><style type='text/css'>table, td {border:1px solid black;border-spacing: 0px; border-collapse: collapse;}</style></head>";
    $message .="<body>";
    $message .="HTTP_HOST: " . $_SERVER["HTTP_HOST"] . "<br>";
    $message .="The following cronjobs were found inactive and were reactivated:<br><br>";
    foreach ($arr_msg as $msg) {
        $message .=$msg . "<br>";
    }
    $message .="</body>";

    $extra = "From: nfxCron Check <$sender>\n";
    $extra .= "Content-Type: text/html\n";
    $extra .= "Content-Transfer-Encoding: 8bit\n";

    mail($to, $subject, $message, $extra);
}

/**
 * remove old log files
 */
function removeOldFiles() {
    $folder = dirname(__FILE__);
    if ($handle = opendir($folder)) {
        $now = date("Y-m-d");
        while (false !== ($file = readdir($handle))) {
            if ($file !== '.' && $file !== '..' && substr($file, 0, 3) == 'log' ) {
                $filename = $folder . DIRECTORY_SEPARATOR . $file;

                $filedate = date('Y-m-d', filemtime($filename));
                $diff = (strtotime($now) - strtotime($filedate)) / (60 * 60 * 24); //it will count no. of days

                if ($diff > 60) {
                    unlink($filename);
                }
            }
        }
        closedir($handle);
    }
}

?>
