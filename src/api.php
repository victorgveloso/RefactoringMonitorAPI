<?php

    require_once "php-jwt/JWT.php";
    require_once "php-jwt/SignatureInvalidException.php";
    require_once "php-jwt/ExpiredException.php";
    require_once "php-jwt/BeforeValidException.php";

    require_once 'PHPMailer/PHPMailerAutoload.php';

    $connection = new mysqli("db-user-public-my51.encs.concordia.ca", "refactor_admin", "dud4M8G$6y54", "refactoring");
    //$connection = new mysqli("127.0.0.1", "davood", "123456", "lambda-study");

    const AllLambdas = 0;
    const OnlyEmailedLambdas = 1;
    const ImInvolvedInLambdas = 2;
    const OnlyEmailedByOthersLambdas = 3;

    header("Access-Control-Allow-Origin: *");
    header("Content-type: application/json");

    if (isset($_REQUEST["projects"])) {

        $projectID = "";
        if (isset($_REQUEST["projectID"])) {
            $projectID = $_REQUEST["projectID"];
        }

        $projectRows = getProjectRows($connection, $projectID);

        echo (json_encode($projectRows));

    } elseif (isset($_REQUEST["lambdas"])) {

        $lambdaID = "";
        if (isset($_REQUEST["lambdaID"])) {
            $lambdaID = $_REQUEST["lambdaID"];
        }

        $projectID = $_REQUEST["projectID"];

        $lambdaRows = getLambdaRows($connection, $projectID, $lambdaID, AllLambdas);
        
        echo (json_encode($lambdaRows));

    } elseif (isset($_REQUEST["emailedLambdas"])) {

        $lambdaRowsMine = getLambdaRows($connection, "", "", ImInvolvedInLambdas);
        $lambdaRowsOthers = getLambdaRows($connection, "", "", OnlyEmailedByOthersLambdas);

        $lambdaRowsAll = array("ByMe" => $lambdaRowsMine, "ByOthers" => $lambdaRowsOthers);

        $projectRows = array();
        foreach ($lambdaRowsAll as $lambdaRowsAllKey => $lambdaRows) {
            foreach ($lambdaRows as $key => $lambdaRow) {
                $projectID = $lambdaRow["projectID"];
                if (!isset($projectRows[$projectID])) {
                    $allProjectsRows = getProjectRows($connection, $projectID);
                    $projectRows[$projectID] = $allProjectsRows[0];
                }
                $lambdaRowsAll[$lambdaRowsAllKey][$key]["project"] = $projectRows[$projectID];
            }
        }
        
        echo (json_encode($lambdaRowsAll));

    } elseif (isset($_REQUEST["monitorProject"])) {

        $user = getUser($_REQUEST["jwt"]);

        if ($user->role == "ADMIN") {

            $projectID = mysqli_real_escape_string($connection, $_REQUEST["projectID"]);
            $shouldMonitor = $_REQUEST["shouldMonitor"] == 'true' ? 1 : 0;
            $q = "UPDATE projectgit SET monitoring_enabled = $shouldMonitor
                    WHERE projectgit.id = $projectID
            ";
            echo(updateQuery($connection, $q));

        } else {
            unauthorized();
        }

    } elseif (isset($_REQUEST["skipLambda"])) {

        $user = getUser($_REQUEST["jwt"]);
        $lambdaID = mysqli_real_escape_string($connection, $_REQUEST["lambdaID"]);
        $status = $_REQUEST["skip"] == 'true' ? 'SKIPPED' : 'NEW';
        $q = "UPDATE lambdastable SET status = '$status'
                WHERE lambdastable.id = $lambdaID
        ";
        echo(updateQuery($connection, $q));

    } elseif (isset($_REQUEST["allTags"])) {

        $user = getUser($_REQUEST["jwt"]);
        $userID = $user->userID;
        $q = "SELECT DISTINCT label FROM tag
                INNER JOIN lambda_tags ON tag.id = lambda_tags.tag
                WHERE lambda_tags.user = $userID";
        echo(selectQuery($connection, $q));

    } elseif (isset($_REQUEST["tagsFor"])) {
        
        $user = getUser($_REQUEST["jwt"]);
        $lambdaID = mysqli_real_escape_string($connection, $_REQUEST["lambdaID"]);
        $userID = mysqli_real_escape_string($connection, $_REQUEST["userID"]);
        $q = "SELECT label FROM tag
                INNER JOIN lambda_tags ON tag.id = lambda_tags.tag
                WHERE lambda_tags.lambda = $lambdaID and lambda_tags.user = $userID";
         echo(selectQuery($connection, $q));

    } elseif (isset($_REQUEST["setTag"])) {
        
        $user = getUser($_REQUEST["jwt"]);
        $lambdaID = mysqli_real_escape_string($connection, $_REQUEST["lambdaID"]);
        $tag = str_replace("\\'", "'", urldecode($_REQUEST["tag"]));
        $tag = mysqli_real_escape_string($connection, $tag);
        $mode = $_REQUEST["mode"];

        if ($mode == "add") {

            $q = "SELECT id FROM tag WHERE tag.label = '$tag'";
            $tagIDRows = getQueryRows($connection, $q);
            if (count($tagIDRows) == 1) {
                $tagID = $tagIDRows[0]["id"];
            } else {
                $q = "INSERT INTO tag(label) VALUES('$tag')";
                if (updateQuery($connection, $q) == '{"status": "OK"}') {
                    $tagID = $connection->insert_id;
                } else {
                    $tagID = -1;
                }
            }
            if (isset($tagID) && $tagID > 0) {
                $q = "INSERT INTO lambda_tags(tag, lambda, user) VALUES($tagID, $lambdaID, $user->userID)";
                echo(updateQuery($connection, $q));
            }

        } elseif ($mode == "remove") {
            $q = "DELETE FROM lambda_tags 
                    WHERE lambda_tags.tag = 
                    (SELECT id FROM tag WHERE tag.label = '$tag')
                    AND lambda_tags.user = $user->userID
                    AND lambda_tags.lambda = $lambdaID";
            echo(updateQuery($connection, $q));
        }

    } else if (isset($_REQUEST["getEmailTemplate"])) {

        $user = getUser($_REQUEST["jwt"]);
        $lambdaID = mysqli_real_escape_string($connection, urldecode($_REQUEST["lambdaID"]));

        $q = "SELECT 
                    `revisiongit`.`authorName`,
                    `revisiongit`.`project` projectID,
                    `projectgit`.`name` projectName,
                    CONVERT(
                        CONCAT(
                            REPLACE(projectgit.cloneUrl,'.git', ''),
                            '/commit/',
                            revisiongit.commitId,
                            '#diff-', 
                            md5(lambdastable.filePath), 
                            'R', 
                            lambdastable.startLine) 
                        USING UTF8) lambdaDiffLink
                FROM lambdastable
                INNER JOIN revisiongit ON lambdastable.revision = revisiongit.id
                INNER JOIN projectgit ON projectgit.id = revisiongit.project
                WHERE lambdastable.id = $lambdaID";

        $projectsInfo = getQueryRows($connection, $q);

        $authorName = $projectsInfo[0]["authorName"];
        $templateVars["authorName"] = $authorName;

        $templateVars["commitUrl"] = $projectsInfo[0]["lambdaDiffLink"];
        $templateVars["projectName"] = $projectsInfo[0]["projectName"];

        $projectID = $projectsInfo[0]["projectID"];
        $templateVars["contributorRank"] = getRankString(getAuthorRank($connection, $projectID, $authorName));
        
        $q = "SELECT p.lambdaDensityPerClass,
                (SELECT COUNT(*) FROM projectgit) projectsCount,
                (SELECT COUNT(*) FROM projectsadditionalinfo pi
                    WHERE pi.lambdaDensityPerClass > p.lambdaDensityPerClass) projectRank
                FROM projectsadditionalinfo p
                WHERE p.project = $projectID
        ";
        $projectsInfo = getQueryRows($connection, $q);

        $templateVars["numberOfAnalyzedProjects"] = 0;
        $templateVars["projectRank"] = 0;
        $templateVars["lambdaDensity"] = 0;

        if (count($projectsInfo) == 1) {
            $templateVars["numberOfAnalyzedProjects"] = $projectsInfo[0]["projectsCount"];
            $templateVars["projectRank"] = $projectsInfo[0]["projectRank"];
            $lambdaDensity = $projectsInfo[0]["lambdaDensityPerClass"];
            $percision = 2;
            do {
                $lambdaDensityRounded = round ($lambdaDensity, $percision);
                $percision++; 
            } while ($lambdaDensityRounded == 0 && $percision < 15);
            $templateVars["lambdaDensity"] = $lambdaDensityRounded;
        }

        $template = json_encode(getEmailBody($templateVars));

        echo "{ \"template\": $template }";

    } elseif (isset($_REQUEST["sendMail"])) {

        $user = getUser($_REQUEST["jwt"]);

        $userFullName = $user->name . " " . $user->familyName;
        $userEmail = $user->email;
        $authorEmail = urldecode($_REQUEST["toEmail"]);
        $emailBody = urldecode($_REQUEST["body"]);
        $subject = urldecode($_REQUEST["subject"]);
        $authorName = urldecode($_REQUEST["to"]);
        $lambdaID = urldecode($_REQUEST["lambda"]);
        $emailMyself = $_REQUEST["emailMyself"] == "true";

        $emailBody = str_replace("\\\"", "\"", $emailBody);
        $emailBody = str_replace("\\'", "'", $emailBody);

        //$headers[] = 'MIME-Version: 1.0';
        //$headers[] = 'Content-type: text/html; charset=iso-8859-1';

        //$headers[] = "To: $authorName <$authorEmail>";
        //$headers[] = "From: $userFullName <$userEmail>";
        //$headers[] = 'Cc: birthdayarchive@example.com';
        //$headers[] = 'Bcc: birthdaycheck@example.com';

        //$result = mail($authorEmail, $subject, $emailBody, implode("\r\n", $headers));
        //$authorEmail = "dmazinanian@gmail.com";

        $bcc = "";
        if ($emailMyself) {
            $bcc = $user->email;
        }

        $result = sendEmail($userEmail, $userFullName, $authorEmail, $subject, $emailBody, $bcc);

        if ($result) {

            $emailBody = mysqli_real_escape_string($connection, $emailBody);
            $authorEmail = mysqli_real_escape_string($connection, $authorEmail);
            $lambdaID = mysqli_real_escape_string($connection, $lambdaID);
            $userEmail = mysqli_real_escape_string($connection, $userEmail);
            $subject = mysqli_real_escape_string($connection, $subject);

            $q = "INSERT INTO surveymail(`alternativeAddress`, `body`, `recipient`, `sentDate`, `sender`, `subject`)
                                VALUES('', '$emailBody', '$authorEmail', NOW(), '$userEmail', '$subject')";
            updateQuery($connection, $q);

            $emailID = $connection->insert_id;

            $q = "INSERT INTO lambdastable_surveymail (`lambdastable_id`, `surveyEmails_id`)
                    VALUES ($lambdaID, $emailID)";

            updateQuery($connection, $q);

            $q = "UPDATE lambdastable SET status = 'MAIL_SENT' WHERE lambdastable.id = $lambdaID";

            echo(updateQuery($connection, $q));
            return;
        }

        echo('{"status":"ERROR"}');
    
    } else if (isset($_REQUEST["addResponse"])) {

        $user = getUser($_REQUEST["jwt"]);

        $emailBody = urldecode($_REQUEST["body"]);
        $emailBody = str_replace("\\\"", "\"", $emailBody);
        $emailBody = str_replace("\\'", "'", $emailBody);

        $userEmail = mysqli_real_escape_string($connection, $user->email);
        $authorEmail = mysqli_real_escape_string($connection, urldecode($_REQUEST["fromEmail"]));
        $emailBody = mysqli_real_escape_string($connection, $emailBody);
        $subject = mysqli_real_escape_string($connection, urldecode($_REQUEST["subject"]));
        $lambdaID = mysqli_real_escape_string($connection, urldecode($_REQUEST["lambda"]));

        $q = "INSERT INTO surveymail(`alternativeAddress`, `body`, `recipient`, `sentDate`, `sender`, `subject`)
                    VALUES('', '$emailBody', '$userEmail', NOW(), '$authorEmail', '$subject')";
        
        updateQuery($connection, $q);

        $emailID = $connection->insert_id;

        $q = "INSERT INTO lambdastable_surveymail (`lambdastable_id`, `surveyEmails_id`)
                    VALUES ($lambdaID, $emailID)";

        echo(updateQuery($connection, $q));

    } else if (isset($_REQUEST{"getMails"})) {

        $user = getUser($_REQUEST["jwt"]);

        if (isset($_REQUEST["lambdaID"])) {

            $lambdaID = mysqli_real_escape_string($connection, $_REQUEST["lambdaID"]);

            $q = "SELECT surveymail.*, (EXISTS(SELECT * FROM users WHERE users.email = surveymail.recipient)) recipientIsUser
                    FROM surveymail
                    INNER JOIN lambdastable_surveymail ON lambdastable_surveymail.surveyEmails_id = surveymail.id
                    WHERE lambdastable_surveymail.lambdastable_id = $lambdaID
                    ORDER BY surveymail.sentDate";

        } elseif (isset($_REQUEST["email"])) {

            $email = mysqli_real_escape_string($connection, urldecode($_REQUEST["email"]));

             $q = "SELECT surveymail.*, lambdastable_surveymail.lambdastable_id,
                        (EXISTS(SELECT * FROM users WHERE users.email = surveymail.recipient)) recipientIsUser
                    FROM surveymail
                    INNER JOIN lambdastable_surveymail ON lambdastable_surveymail.surveyEmails_id = surveymail.id
                    WHERE surveymail.recipient LIKE '$email' OR surveymail.sender LIKE '$email'
                    ORDER BY surveymail.sentDate";
        }

        echo(selectQuery($connection, $q));

    } elseif (isset($_REQUEST["login"]) && isset($_REQUEST["u"]) && isset($_REQUEST["p"])) {
        login($connection, $_REQUEST["u"], $_REQUEST["p"]);
    } elseif (isset($_REQUEST["sha"])) {
        //echo hashPassword($_REQUEST["p"]);
    }

function getProjectRows($connection, $projectID) {
    $whereClause = "";
    if ($projectID != "") {
        $projectID = mysqli_real_escape_string($connection, $projectID);
        $whereClause = "WHERE projectgit.id = $projectID";
    } 
    $qur = "SELECT projectgit.*, COUNT(lambdastable.id) AS numberOfLambdas,
            COUNT(CASE 
                WHEN lambdastable.status = 'NEW' THEN lambdastable.status
                ELSE NULL
            END) AS numberOfNewLambdas
            FROM projectgit 
                LEFT OUTER JOIN revisiongit ON projectgit.id = revisiongit.project
                LEFT OUTER JOIN lambdastable ON lambdastable.revision = revisiongit.id
            $whereClause
            GROUP BY projectgit.id";

    return getQueryRows($connection, $qur);
}

function getLambdaRows($connection, $projectID, $lambdaID, $emailedLambdasMode) {

    $whereClause = "";
    $extraColumns = "";

    if ($projectID != "") {
        $projectID = mysqli_real_escape_string($connection, $projectID);
        $whereClause = "revisiongit.project = $projectID";
    }

    if ($lambdaID != "") {
        $user = getUser($_REQUEST["jwt"]);
        $userID = $user->userID;
        $lambdaID = mysqli_real_escape_string($connection, $lambdaID);
        $whereClause = "lambdastable.id = $lambdaID";
    }

    if ($emailedLambdasMode == AllLambdas) {

        $extraColumns = ",  (EXISTS
                                (SELECT surveymail.id
                                    FROM surveymail
                                    INNER JOIN lambdastable_surveymail ON lambdastable_surveymail.surveyEmails_id = surveymail.id
                                    WHERE (surveymail.recipient = revisiongit.authorEmail OR surveymail.sender = revisiongit.authorEmail)
                                )
                            ) authorContacted";

    } else {
        
        $user = getUser($_REQUEST["jwt"]);
        $userID = $user->userID;
        $userEmail = $user->email;

         if ($whereClause != "") {
            $whereClause .= " AND ";
        }

        $extraColumns = ",  (EXISTS
                                (SELECT surveymail.id
                                    FROM surveymail
                                    INNER JOIN lambdastable_surveymail ON lambdastable_surveymail.surveyEmails_id = surveymail.id
                                    WHERE lambdastable_surveymail.lambdastable_id = lambdastable.id AND
                                        surveymail.recipient IN (SELECT email FROM users)
                                )
                            ) responded,
                            (EXISTS 
                                (SELECT * FROM lambda_tags 
                                    WHERE lambda_tags.lambda = lambdastable.id AND
                                          lambda_tags.user = $userID
                                    )
                            ) tagged";

        switch ($emailedLambdasMode) {
            case OnlyEmailedLambdas:
                $whereClause .= "EXISTS(SELECT surveymail.id
                            FROM surveymail
                            INNER JOIN lambdastable_surveymail ON lambdastable_surveymail.surveyEmails_id = surveymail.id
                            WHERE lambdastable_surveymail.lambdastable_id = lambdastable.id)";
                break;
            case ImInvolvedInLambdas:
                $whereClause .= "EXISTS(SELECT surveymail.id
                                    FROM surveymail
                                    INNER JOIN lambdastable_surveymail ON lambdastable_surveymail.surveyEmails_id = surveymail.id
                                    WHERE lambdastable_surveymail.lambdastable_id = lambdastable.id
                                        AND (surveymail.recipient LIKE '$userEmail' OR surveymail.sender LIKE '$userEmail')
                                )";
                break;
            case OnlyEmailedByOthersLambdas:
                $whereClause .= "(EXISTS(SELECT surveymail.id
                                    FROM surveymail
                                    INNER JOIN lambdastable_surveymail ON lambdastable_surveymail.surveyEmails_id = surveymail.id
                                    WHERE lambdastable_surveymail.lambdastable_id = lambdastable.id) 
                                ) AND (NOT EXISTS(SELECT surveymail.*
                                    FROM surveymail
                                        INNER JOIN lambdastable_surveymail ON lambdastable_surveymail.surveyEmails_id = surveymail.id
                                        WHERE lambdastable_surveymail.lambdastable_id = lambdastable.id
                                    AND (surveymail.recipient LIKE '$userEmail' OR surveymail.sender LIKE '$userEmail'))
                                )";
                break;
        }

    }

    if ($whereClause != "") {
        $whereClause = "WHERE " . $whereClause;
    }

    $q = "SELECT
        lambdastable.id, lambdastable.filePath, 
        lambdastable.body, lambdastable.startLine, 
        lambdastable.endLine, lambdastable.numberOfParameters, lambdastable.revision,
        lambdastable.status AS lambda_status,
        lambdastable.lambdaLocationStatus,
        lambdastable.parent,
        lambdastable.lambdaString,
        md5(lambdastable.filePath) AS fileMd5,
        revisiongit.id commitRowID,
        revisiongit.authorEmail, revisiongit.authorName, 
        revisiongit.commitId commitSHA1, revisiongit.commitTime,
        revisiongit.project projectID
        $extraColumns
    FROM lambdastable 
    INNER JOIN revisiongit ON lambdastable.revision = revisiongit.id
    $whereClause
    ORDER BY lambdastable.status DESC";

    $lambdaRows = getQueryRows($connection, $q);

    $q = "SELECT lambdastable.id AS lambdaid, lambdaparameterstable.* 
            FROM lambdastable
            INNER JOIN revisiongit ON lambdastable.revision = revisiongit.id 
            LEFT OUTER JOIN lambdaparameterstable ON lambda = lambdastable.id
            $whereClause";

    $lambdaParameterRows = getQueryRows($connection, $q);

    $lambdaParameters = array();
    foreach ($lambdaParameterRows as $lambdaParameter) {
        if (!isset($lambdaParameters[$lambdaParameter["lambdaid"]])) {
            $lambdaParameters[$lambdaParameter["lambdaid"]] = array();
        }
        if (isset($lambdaParameter["id"])) {
            $lambdaParameters[$lambdaParameter["lambdaid"]][] = $lambdaParameter; 
        }
    }

    foreach ($lambdaRows as $key => $lambda) {
        $lambdaRows[$key]["parameters"] = $lambdaParameters[$lambda["id"]];
    }

    if ($lambdaID != "") {

        $authorName = $lambdaRows[0]["authorName"];

        $lambdaRows[0]["authorRank"] = getAuthorRank($connection, $projectID, $authorName);

        $q = "SELECT lambdastable.id AS lambdaid, tag.* 
            FROM lambdastable
            LEFT OUTER JOIN lambda_tags ON lambda_tags.lambda = lambdastable.id
            LEFT OUTER JOIN tag ON tag.id = lambda_tags.tag
            WHERE lambdastable.id = $lambdaID AND lambda_tags.user = $userID";
        
        $lambdaTagsRows = getQueryRows($connection, $q);

        $lambdaRows[0]["tags"] = $lambdaTagsRows;
    }

    return $lambdaRows;
}

function sendEmail($from, $fromName, $to, $subject, $body, $bcc) {
 
    $mail = new PHPMailer(); // create a new object
    $mail->IsSMTP(); // enable SMTP
    $mail->SMTPDebug = 0; // debugging: 1 = errors and messages, 2 = messages only
    $mail->SMTPAuth = false; // authentication enabled
    #$mail->SMTPSecure = 'ssl'; // secure transfer enabled REQUIRED for Gmail
    $mail->Host = "smtp.encs.concordia.ca";
    $mail->Port = 25; // or 587
    $mail->IsHTML(true);
    #$mail->Username = "";
    #$mail->Password = "";
    $mail->SetFrom($from);
    $mail->FromName = $fromName;
    $mail->Subject = $subject;
    $mail->Body = $body;
    //$mail->AlternativeBody = getPlainTextMessage($row,$id);
    $mail->AddAddress($to);
    if (isset($bcc) && $bcc != "") {
        $mail->AddBCC($bcc);
    }

    return $mail->Send();

}

function selectQuery($connection, $query) {
    $rows = getQueryRows($connection, $query);
    return json_encode($rows);
}

function getQueryRows($connection, $query) {
    $result = $connection->query($query);
    $rows = array();
    if ($result->num_rows > 0) {
        while($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    return utf8ize($rows);
}

function updateQuery($connection, $query) {
    if ($connection->query($query) === TRUE) {
       return '{"status": "OK"}';
    } else {
        return $connection->error;
    }
}

function utf8ize($d) {
    if (is_array($d)) {
        foreach ($d as $k => $v) {
            $d[$k] = utf8ize($v);
        }
    } else if (is_string ($d)) {
        return utf8_encode($d);
    }
    return $d;
}

function getSecretKey() {
    //die(base64_encode(openssl_random_pseudo_bytes(256)));
    return "nvolp5iDf4KkqVJ8n35nSLA+JogDD4gzH+Q9aSDPIHvgvpmYA9mbXLuj2LGiYSw+MSHGhSJaxEWXiG9nzLYFH/6VF9jpADp7yV38oCBVjaOD5f2BUtz2Ni3J0fUV9wSVr7e5RDwUgLPZnI5ItZmxt3kZFXMTD+DgwUorQTW4ropCkS9bdbKTxKvFN2WcU/YGsOrgdC1+/M/IZz4w4h4WLGUpWZ02XVfOrYVQcgHb3xmUZRdtO9sbb+4zxrfP7FvFQ9p7CfEzyiMmpayVqQo28DNz4RxA70M6M1xTbpwZev1Wp/GJ8EuFC307U+A8F1LS6Us0ApsWC0sT6Bfz4Cztkw==";
}

function hashPassword($password) {
    $salt = "THEALMIGHTY";
    return sha1($password . $salt);
}

function login($connection, $username, $password) {

    $username = mysqli_real_escape_string($connection, strtolower($username));
    $password = hashPassword($password);

    $q = "SELECT * FROM users WHERE userName = '$username' AND password = '$password'";
    $userRow = getQueryRows($connection, $q);

    if (count($userRow) == 1 && $userRow[0]["userName"] == $username && $userRow[0]["password"] == $password) {

        $tokenId    = base64_encode(uniqid());
        $issuedAt   = time();
        $notBefore  = $issuedAt + 10;
        $expire     = $notBefore + (7 * 24 * 60 * 60);
        $serverName = 'refactoring';
        
        $data = array(
            'iat'  => $issuedAt,
            'jti'  => $tokenId,
            'iss'  => $serverName,
            'nbf'  => $notBefore,
            'exp'  => $expire,
            'data' => array(
                'userID'     => $userRow[0]["id"],
                'userName'   => $userRow[0]["userName"], 
                'name'       => $userRow[0]["name"],
                'familyName' => $userRow[0]["familyName"], 
                'role'       => $userRow[0]["userRole"],
                'email'      => $userRow[0]["email"]
            )
        );

        $secretKey = base64_decode(getSecretKey());

        $jwt = \Firebase\JWT\JWT::encode($data, $secretKey, 'HS512');
            
        $unencodedArray = array('jwt' => $jwt);
        echo json_encode($unencodedArray);

    } else {
        echo '{"status": "UNAUTHORIZED"}';
    }
}

function getUser($jwt) {

    if (isset($jwt)) {
        try {
            $secretKey = base64_decode(getSecretKey());
            $token = \Firebase\JWT\JWT::decode($jwt, $secretKey, array('HS512'));
            return $token->data;
        } catch (Exception $e) {
            echo $e;
        }
    }

    unauthorized();
}

function getAuthorRank($connection, $projectID, $authorName) {
    $q = "SELECT COUNT(*) + 1 authorRank FROM (
        SELECT COUNT(*) numberOfLambdas FROM lambdastable
            INNER JOIN revisiongit ON lambdastable.revision = revisiongit.id
            WHERE revisiongit.project = $projectID
            GROUP BY revisiongit.authorName
            HAVING COUNT(*) > 
                (SELECT COUNT(*) FROM lambdastable l
                    INNER JOIN revisiongit r ON l.revision = r.id
                    WHERE 
                        r.authorName = '$authorName' AND
                        r.project = $projectID)
    ) t1";
    $rows = getQueryRows($connection, $q);
    return $rows[0]["authorRank"];
}

function getRankString($number) {
    if ($number <= 3) {
        $r = $number;
        switch ($number) {
            case 1: 
                $r .= "st";
                break;
            case 2: 
                $r .= "nd";
                break;
            case 3: 
                $r .= "rd";
                break;
        }
        return "positioned as the " . $r . " contributor";
    } else {
        if ($number % 5 != 0) {
            $topRank = (floor($number / 5) + 1) * 5;
        } else {
            $topRank = floor($number / 5) * 5;
        }
        return "among the top-" . $topRank . " contributors";
    }
}

function getEmailBody($templateVars) {
    
    extract($templateVars);

    if ($lambdaDensity > 1) {
        $lambdas = "lambdas";
    } else {
        $lambdas = "lambda";
    }

    return <<<EMAIL
    <p>Dear $authorName,</p>

    <p>We are a group of researchers from Oregon State University, USA 
        and Concordia University, Montreal, Canada,
        investigating the usage of Lambda expressions in code.</p>

    <p>We found out that you are <b>$contributorRank</b> of code 
        using Lambda expressions in <b>$projectName</b>, 
        and this project is ranked at position 
        <b>$projectRank</b> among the <b>$numberOfAnalyzedProjects</b> examined 
        top-starred projects hosted on GitHub with an average density of <b>$lambdaDensity</b> $lambdas per class.</p>

    <p>Therefore, we consider you are an expert on Lambda expressions, and we would like to ask you the 
        following questions for a specific Lambda expression you recently introduced and can be inspected in 
        <a href="$commitUrl" target="_blank">$commitUrl</a>:
    </p>

    <ul>
        <li>Why did you introduce this Lambda expression?</li>
        <li>Did you introduce it manually or used an automated tool (quick fix/assist, refactoring)?</li>
        <li>What IDE are you using?</li>
    </ul>

    <p>Your response will be anonymized in our study and will be used only for research purposes.</p>

    <p></p>
    <p>Thank you very much for your help and participation in our study.
        If you are interested in the results of this study, please let us know.
        We will contact you once the study is concluded.
        </p>

    <p style="margin-top: 30px">
        Davood Mazinanian (<a href="http://dmazinanian.me/" target="_blank">http://dmazinanian.me/</a>)<br />
        Ameya Ketkar (<a href="https://github.com/ameyaKetkar" target="_blank">https://github.com/ameyaKetkar</a>)<br />
        Nikolaos Tsantalis (<a href=" https://users.encs.concordia.ca/~nikolaos/" target="_blank">https://users.encs.concordia.ca/~nikolaos/</a>)<br />
        Danny Dig (<a href="http://dig.cs.illinois.edu/" target="_blank">http://dig.cs.illinois.edu/</a>)
    </p>
EMAIL;
}

function unauthorized() {
    header('HTTP/1.0 401 Unauthorized');
    die();
}

$connection->close();
   
?>