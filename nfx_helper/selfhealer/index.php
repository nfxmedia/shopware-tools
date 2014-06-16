<?php 
error_reporting(E_ALL);
DEFINE("LAST_RUN_FILE","lastrun.txt");
DEFINE("LOG_FILE","log.txt");
DEFINE("FROM_EMAIL","support@nfxmedia.de");
DEFINE("TO_EMAIL","cron_debug@nfxmedia.de");
DEFINE("SUBJECT","Cron Check Warning");

$db = include('../../config.php');
$DB_HOST=$db["db"]["host"];
$DB_USER=$db["db"]["username"];
$DB_PASSWORD=$db["db"]["password"];
$DB_DATABASE=$db["db"]["dbname"];

chmod(dirname(__FILE__), 0777);

//connect to database
echo "Connecting to database...<br /> ";

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASSWORD, $DB_DATABASE);

if ($conn->connect_errno) {
	die ("Failed to connect to database: " . $conn->connect_error);
}
else {
	echo "Database connection successfully established.<br />";
}

// get the status of the cronjobs from previous run
$arr_last = array();
if(file_exists(LAST_RUN_FILE)){
	$text = file_get_contents(LAST_RUN_FILE);
	$lines = explode ("\r\n", $text);
	foreach($lines as $line){
		$arr_last[] = explode("\t", $line);
	}
}
// get the list of all installed cronjobs
$arr_msg = array();
$text_last = "";
$sql = "SELECT name, active, start, end, next, elementID FROM s_crontab WHERE name LIKE '%nfx%'";
$result = $conn->query($sql);
$row = $result->fetch_assoc();
while ($row) {
	$msg = "Name: ".$row["name"]."; Active: ".$row["active"]."; Start: ".$row["start"].";";
	$msg .= " End: ".$row["end"]."; Next: ".$row["next"]."; elementID: ".$row["elementID"].";";
	$text_last .= $row["name"]."\t".$row["active"]."\t".$row["start"]."\t".$row["end"]."\t".$row["next"]."\t".$row["elementID"]."\r\n";
	logMessage(LOG_FILE, $msg);
	$active = $row["active"];
	if($active){
		if(!$row["end"]){
			//check previous message
			foreach($arr_last as $cron){
				if($row["name"] == $cron[0]){
					//"end" is empty and the cron status is not changes since last run => there is something wrong
					$active = !(($row["active"] == $cron[1]) && ($row["start"] == $cron[2]) && ($row["end"] == $cron[3]) && 
							($row["next"] == $cron[4]) && ($row["elementID"] == $cron[5]));					
					break;
				}
			}
		}
	}
	if(!$active){
		$sql = "UPDATE s_crontab 
		SET active = 1,
			end = NOW(),
			next = NOW()
		WHERE name LIKE '".$row["name"]."'";
		$result2 = $conn->query($sql);
		$arr_msg[] = $msg;
		logMessage(LOG_FILE, "--- reactivated");
	}else{
		logMessage(LOG_FILE, "--- ok");
	}
	
	$row = $result->fetch_assoc();
}

logLastRun(LAST_RUN_FILE, $text_last);

if(count($arr_msg)){
	if(TO_EMAIL){
		echo "Send warning email ...<br>";
		sendEmail(FROM_EMAIL, TO_EMAIL, SUBJECT, $arr_msg);
	}
}

echo "END";

function logMessage($filename, $msg){
	try
	{
		if( $fh = @fopen( dirname(__FILE__)."/".$filename, "a+" ) )
		{
			fputs( $fh, date("Y-m-d H:i:s").": ".$msg."\r\n" );
		}
	
		@fclose($fh);
	}
	catch(Exception $ex)
	{
	}
	echo $msg."<br>";
}
function logLastRun($filename, $text_last){
	try
	{
		if( $fh = @fopen( dirname(__FILE__)."/".$filename, "w" ) )
		{
			fputs( $fh, $text_last );
		}
	
		@fclose($fh);
	}
	catch(Exception $ex)
	{
	}
}
function sendEmail($sender, $to, $subject, $arr_msg){
	$message = "<head><style type='text/css'>table, td {border:1px solid black;border-spacing: 0px; border-collapse: collapse;}</style></head>";
	$message .="<body>";
	$message .="HTTP_HOST: ".$_SERVER["HTTP_HOST"]."<br>";
	$message .="The following cronjobs were found inactive and were reactivated:<br><br>";
	foreach($arr_msg as $msg){
		$message .=$msg."<br>";
	}
	$message .="</body>";
	
	$extra = "From: nfxCron Check <$sender>\n";
	$extra .= "Content-Type: text/html\n";
	$extra .= "Content-Transfer-Encoding: 8bit\n";
	
	mail($to,$subject, $message, $extra);
}
?>