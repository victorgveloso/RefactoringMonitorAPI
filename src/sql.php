<?php
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
/**
 * Deprecated: use getCommitRefactoringsCount instead
 */
function getAuthorRankRefactoring($connection, $projectID, $commitID) {
    $whereClause = "WHERE revisiongit.project = $projectID";
    if ($refactoringType != "") {
        $refactoringType = mysqli_real_escape_string($connection, $refactoringType);
        $whereClause .= " AND refactoringgit.refactoringType = '$refactoringType'";
    }
    $q = "SELECT COUNT(*) + 1 authorRank FROM (
        SELECT COUNT(*) numberOfRefactorings FROM refactoringgit
            INNER JOIN revisiongit ON refactoringgit.revision = revisiongit.id
            $whereClause
            GROUP BY revisiongit.authorName
            HAVING COUNT(*) > 
                (SELECT COUNT(*) FROM refactoringgit l
                    INNER JOIN revisiongit r ON l.revision = r.id
                    WHERE 
                        r.authorName = '$authorName' AND
                        r.project = $projectID
                        $whereClause)
    ) t1";
    $rows = getQueryRows($connection, $q);
    return $rows[0]["authorRank"];
}
function getCommitRefactoringsCount($connection, $projectID, $authorEmail, $refactoringType) {
    
    $whereClause = "";
    if ($projectID != "") {
        $whereClause = "WHERE revisiongit.project = $projectID";
    }
    $q = "SELECT c.authorName , c.authorEmail, COUNT(c.id) AS refactoringCommitsCount
    FROM (
        SELECT DISTINCT revisiongit.id, revisiongit.authorName, revisiongit.authorEmail 
        FROM revisiongit
        INNER JOIN refactoringgit r ON r.revision = revisiongit.id
        $whereClause
        ) c
    GROUP BY c.authorEmail
    HAVING c.authorEmail = '$authorEmail';";
    $rows = getQueryRows($connection, $q);
    return $rows[0]["refactoringCommitsCount"];
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
function selectQuery($connection, $query) {
    $rows = getQueryRows($connection, $query);
    return json_encode($rows);
}

function getProjectRows($connection, $projectID) {
    $whereClause = "";
    if ($projectID != "") {
        $projectID = mysqli_real_escape_string($connection, $projectID);
        $whereClause = "WHERE projectgit.id = $projectID";
    } 
    $qur = "SELECT projectgit.*, COUNT(lambdastable.id) AS numberOfLambdas, COUNT(revisiongit.id) AS numberOfCommits,
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
function updateQuery($connection, $query) {
    if ($connection->query($query) === TRUE) {
       return '{"status": "OK"}';
    } else {
        return $connection->error;
    }
}
?>