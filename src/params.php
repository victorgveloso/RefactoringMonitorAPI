<?php
abstract class Parameter {
    private $decorated;
    protected $connection;
    public function __construct(Parameter $decorated = null) {
        global $globalConnection;
        $this->connection = $globalConnection;
        $this->decorated = $decorated;
    }
    protected abstract function do();
    protected abstract function name() : string;
    public function setNext(Parameter $decorated) : Returntype {
        if (is_null($this->decorated)) {
            $this->decorated = $decorated;
        }
        else {
            $this->decorated->setNext($decorated);
        }
    }
    public function isEnabled() : bool {
        return isset($_REQUEST[$this->name()]);
    }
    public function handle() {
        if ($this->isEnabled()) {
            $this->do();
        } elseif (isset($this->decorated)) {
            $this->decorated->handle();
        } else {
            $this->connection->close();
        }
    }
}
class Projects extends Parameter {
    protected function do() {
        $projectID = "";
        if (isset($_REQUEST["projectID"])) {
            $projectID = $_REQUEST["projectID"];
        }

        $projectRows = getProjectRows($this->connection, $projectID);

        echo (json_encode($projectRows));
    }
    protected function name() : string {
        return "projects";
    }
}
class CodeRange extends Parameter {
    protected function do() {
        $refactoringID = $_REQUEST["refactoringID"];
    
        $q = "SELECT r.id AS refactoringId, r.description AS refactoringDescription, r.refactoringType, cr.*, rg.commitId, pg.cloneUrl,
                    CONCAT(LEFT(pg.cloneUrl, LENGTH(pg.cloneUrl)-4),'/commit/',rg.commitId, '#diff-', SHA2(cr.filePath,256), 'R', cr.startLine, '-R', cr.endLine) AS refactoringLink
            FROM refactoringgit r
            LEFT OUTER JOIN revisiongit rg  ON rg.id = r.revision
            LEFT OUTER JOIN projectgit pg  ON pg.id = rg.project
            LEFT OUTER JOIN coderangegit cr ON cr.refactoring = r.id
            WHERE r.id = $refactoringID";
        $codeRangeRows = getQueryRows($this->connection, $q);
        echo (json_encode($codeRangeRows));
    }
    protected function name() : string {
        return "coderanges";
    }
}
class Refactorings extends Parameter {
    protected function getRefactoringRows($connection, $projectID, $refactoringID, $emailingRule, $refactoringType = "", $testRefactoringOnly = false, $limit = null, $offset = null) {
        $whereClauses = array();
        $whereClause = "";
        $extraColumns = "";
        $extraClauses = "";

        if (!(is_null($limit) && is_null($offset))) {
            $offset = $offset == null ? 0 : $offset;
            $limit = $limit == null ? 100 : $limit;
            $extraClauses = "ORDER BY r.id ASC
                            LIMIT $limit
                            OFFSET $offset";
        }

        if ($refactoringType != "") {
            array_push($whereClauses, "r.refactoringType = '$refactoringType'");
        }

        if ($testRefactoringOnly != "") {
            array_push($whereClauses, "r.isTestRefactoring = 1");
        }
    
        if ($projectID != "") {
            $projectID = mysqli_real_escape_string($connection, $projectID);
            array_push($whereClauses, "rg.project = $projectID");
        }
    
        if ($emailingRule != 'AllRefactorings') {
            $user = getUser($_REQUEST["jwt"]);
            $userID = $user->userID;
            $userEmail = $user->email;
        }

        if ($refactoringID != "") {
            $refactoringID = mysqli_real_escape_string($connection, $refactoringID);
            array_push($whereClauses, "r.id = $refactoringID");
        }
    
        if (isset($userEmail) && $userEmail != "") {
            switch ($emailingRule) {
                case "ImInvolvedInRefactorings":
                    array_push($whereClauses, "sm.sender = '$userEmail'");
            break;
                case "OnlyEmailedByOthersRefactorings":
                    array_push($whereClauses, "sm.sender != '$userEmail'");
                    break;
            }
            $extraColumns = ", COUNT(resp.id) > 0 AS responded , sm.*";
            $extraClauses = "GROUP BY r.id" . $extraClauses;
        }

        if (count($whereClauses) > 0) {
            $whereClause = "WHERE " . array_pop($whereClauses);
            foreach ($whereClauses as $clause) {
                $whereClause = $whereClause . " AND " . $clause;
            }
        }
    
    
        $q = "SELECT    r.id AS refactoringId, tag.*, r.refactoringType, r.description, rg.status, r.description AS refactoringString,
                        rg.id AS commitRowId, rg.commitId, rg.authorEmail, rg.authorName, rg.FullMessage, rg.project, rg.FullMessage,
                        rg.commitTime, EXISTS(SELECT cr.id FROM coderangegit cr WHERE cr.refactoring = r.id AND (cr.filePath LIKE '%Test.java' OR cr.filePath LIKE '%/test/%')) AS isTestRefactoring, 
                        (SELECT cr.filePath FROM coderangegit cr WHERE cr.refactoring = r.id AND cr.diffSide = 'RIGHT' LIMIT 1) AS filePath
                        $extraColumns
            FROM refactoringgit r
            LEFT OUTER JOIN revisiongit rg  ON r.revision = rg.id
            LEFT OUTER JOIN refactoringmotivation rm ON rm.refactoring = r.id
            LEFT OUTER JOIN tag ON tag.id = rm.tag
            LEFT OUTER JOIN surveymail sm ON sm.revision = rg.id
            LEFT OUTER JOIN surveyresponse resp ON resp.survey = sm.id 
            $whereClause
            $extraClauses";
        $refactoringRows = getQueryRows($connection, $q);
    
        if ($refactoringID != "") {
            $authorEmail = $refactoringRows[0]["authorEmail"];
    
            $refactoringRows[0]["numberOfContributions"] = getCommitRefactoringsCount($connection, $projectID, $authorEmail, "");
    
            $q = "SELECT tag.*
                FROM refactoringmotivation rm
                LEFT OUTER JOIN tag ON tag.id = rm.tag
                WHERE rm.refactoring = $refactoringID";
            $refactoringTagsRows = getQueryRows($connection, $q);
            $refactoringRows[0]["tags"] = $refactoringTagsRows;
        }
    
        return $refactoringRows;
    }
    protected function do() {
        $refactoringID = "";
        if (isset($_REQUEST["refactoringID"])) {
            $refactoringID = $_REQUEST["refactoringID"];
        }

        $limit = isset($_REQUEST["limit"]) ? $_REQUEST["limit"] : null;
        $offset = isset($_REQUEST["offset"]) ? $_REQUEST["offset"] : null;

        $projectID = $_REQUEST["projectID"];

        $refactoringType = $_REQUEST["refactoringType"];
        $testRefactoringOnly = $_REQUEST["testRefactoringOnly"];

        $refactoringRows = $this->getRefactoringRows($this->connection, $projectID, $refactoringID, 'AllRefactorings', $refactoringType, $testRefactoringOnly, $limit, $offset);
        
        echo (json_encode($refactoringRows));
    }
    protected function name() : string {
        return "refactorings";
    }
}
class Lambdas extends Parameter {
    protected function do() {
        $lambdaID = "";
        if (isset($_REQUEST["lambdaID"])) {
            $lambdaID = $_REQUEST["lambdaID"];
        }

        $projectID = $_REQUEST["projectID"];

        $lambdaRows = getLambdaRows($this->connection, $projectID, $lambdaID, AllLambdas);
        
        echo (json_encode($lambdaRows));
    }
    protected function name() : string {
        return "lambdas";
    }
}
class EmailedRefactorings extends Refactorings {
    protected function do() {
        $refactoringRowsMine = $this->getRefactoringRows($this->connection, "", "", "ImInvolvedInRefactorings");
        $refactoringRowsOthers = $this->getRefactoringRows($this->connection, "", "", "OnlyEmailedByOthersRefactorings");

        $refactoringRowsAll = array("ByMe" => $refactoringRowsMine, "ByOthers" => $refactoringRowsOthers);

        $projectRows = array();
        foreach ($refactoringRowsAll as $refactoringRowsAllKey => $refactoringRows) {
            foreach ($refactoringRows as $key => $refactoringRow) {
                $projectID = $refactoringRow["project"];
                if (!isset($projectRows[$projectID])) {
                    $allProjectsRows = getProjectRows($this->connection, $projectID);
                    $projectRows[$projectID] = $allProjectsRows[0];
                }
                $refactoringRowsAll[$refactoringRowsAllKey][$key]["project"] = $projectRows[$projectID];
            }
        }
        echo (json_encode($refactoringRowsAll));
    }
    protected function name() : string {
        return "emailedRefactorings";
    }
}
class EmailedLambdas extends Parameter {
    protected function do() {

        $lambdaRowsMine = getLambdaRows($this->connection, "", "", ImInvolvedInLambdas);
        $lambdaRowsOthers = getLambdaRows($this->connection, "", "", OnlyEmailedByOthersLambdas);

        $lambdaRowsAll = array("ByMe" => $lambdaRowsMine, "ByOthers" => $lambdaRowsOthers);

        $projectRows = array();
        foreach ($lambdaRowsAll as $lambdaRowsAllKey => $lambdaRows) {
            foreach ($lambdaRows as $key => $lambdaRow) {
                $projectID = $lambdaRow["projectID"];
                if (!isset($projectRows[$projectID])) {
                    $allProjectsRows = getProjectRows($this->connection, $projectID);
                    $projectRows[$projectID] = $allProjectsRows[0];
                }
                $lambdaRowsAll[$lambdaRowsAllKey][$key]["project"] = $projectRows[$projectID];
            }
        }
        
        echo (json_encode($lambdaRowsAll));
    }
    protected function name() : string {
        return "emailedLambdas";
    }
}
class MonitorProject extends Parameter {
    protected function do() {
        $user = getUser($_REQUEST["jwt"]);

        if (strtoupper($user->role) == "ADMIN") {

            $projectID = mysqli_real_escape_string($this->connection, $_REQUEST["projectID"]);
            $shouldMonitor = $_REQUEST["shouldMonitor"] == 'true' ? 1 : 0;
            $q = "UPDATE projectgit SET monitoring_enabled = $shouldMonitor
                    WHERE projectgit.id = $projectID
            ";
            echo(updateQuery($this->connection, $q));

        } else {
            unauthorized();
        }
    }
    protected function name() : string {
        return "monitorProject";
    }
}
class SkipLambda extends Parameter {
    protected function do() {
        $user = getUser($_REQUEST["jwt"]);
        $lambdaID = mysqli_real_escape_string($this->connection, $_REQUEST["lambdaID"]);
        $status = $_REQUEST["skip"] == 'true' ? 'SKIPPED' : 'NEW';
        $q = "UPDATE lambdastable SET status = '$status'
                WHERE lambdastable.id = $lambdaID
        ";
        echo(updateQuery($this->connection, $q));
    }
    protected function name() : string {
        return "skipLambda";
    }
}
class AllTags extends Parameter {
    protected function do() {
        $user = getUser($_REQUEST["jwt"]);
        $userID = $user->userID;
        $q = "SELECT DISTINCT label FROM tag
                LEFT OUTER JOIN lambda_tags ON tag.id = lambda_tags.tag
                LEFT OUTER JOIN refactoringmotivation ON tag.id = refactoringmotivation.tag
                WHERE lambda_tags.user = $userID OR lambda_tags.user IS NULL";
        echo(selectQuery($this->connection, $q));
    }
    protected function name() : string {
        return "allTags";
    }
}
class TagsFor extends Parameter {
    protected function do() {
        $user = getUser($_REQUEST["jwt"]);
        $lambdaID = mysqli_real_escape_string($this->connection, $_REQUEST["lambdaID"]);
        $userID = mysqli_real_escape_string($this->connection, $_REQUEST["userID"]);
        $q = "SELECT label FROM tag
                INNER JOIN lambda_tags ON tag.id = lambda_tags.tag
                WHERE lambda_tags.lambda = $lambdaID and lambda_tags.user = $userID";
        echo(selectQuery($this->connection, $q));
    }
    protected function name() : string {
        return "tagsFor";
    }
}
class SetTag extends Parameter {
    private function tagLambda() {
        $lambdaID = mysqli_real_escape_string($this->connection, $_REQUEST["lambdaID"]);
        $tag = str_replace("\\'", "'", urldecode($_REQUEST["tag"]));
        $tag = mysqli_real_escape_string($this->connection, $tag);
        $mode = $_REQUEST["mode"];

        if ($mode == "add") {

            $q = "SELECT id FROM tag WHERE tag.label = '$tag'";
            $tagIDRows = getQueryRows($this->connection, $q);
            if (count($tagIDRows) == 1) {
                $tagID = $tagIDRows[0]["id"];
            } else {
                $q = "INSERT INTO tag(label) VALUES('$tag')";
                if (updateQuery($this->connection, $q) == '{"status": "OK"}') {
                    $tagID = $this->connection->insert_id;
                } else {
                    $tagID = -1;
                }
            }
            if (isset($tagID) && $tagID > 0) {
                $q = "INSERT INTO lambda_tags(tag, lambda, user) VALUES($tagID, $lambdaID, $user->userID)";
                echo(updateQuery($this->connection, $q));
            }

        } elseif ($mode == "remove") {
            $q = "DELETE FROM lambda_tags 
                    WHERE lambda_tags.tag = 
                    (SELECT id FROM tag WHERE tag.label = '$tag')
                    AND lambda_tags.user = $user->userID
                    AND lambda_tags.lambda = $lambdaID";
            echo(updateQuery($this->connection, $q));
        }
    }
    private function tagRefactoring() {
        $refactoringID = mysqli_real_escape_string($this->connection, $_REQUEST["refactoring"]);
        $tag = str_replace("\\'", "'", urldecode($_REQUEST["tag"]));
        $tag = mysqli_real_escape_string($this->connection, $tag);
        $mode = $_REQUEST["mode"];

        if ($mode == "add") {
            $q = "SELECT id FROM tag WHERE tag.label = '$tag'";
            $tagIDRows = getQueryRows($this->connection, $q);
            if (count($tagIDRows) == 1) {
                $tagID = $tagIDRows[0]["id"];
            } else {
                $q = "INSERT INTO tag(label) VALUES('$tag')";
                if (updateQuery($this->connection, $q) == '{"status": "OK"}') {
                    $tagID = $this->connection->insert_id;
                } else {
                    $tagID = -1;
                }
            }
            if (isset($tagID) && $tagID > 0) {
                $q = "INSERT INTO refactoringmotivation(tag, refactoring) VALUES($tagID, $refactoringID)";
                echo(updateQuery($this->connection, $q));
            }

        } elseif ($mode == "remove") {
            $q = "DELETE FROM refactoringmotivation 
                    WHERE refactoringmotivation.tag = 
                    (SELECT id FROM tag WHERE tag.label = '$tag')
                    AND refactoringmotivation.refactoring = $refactoringID";
            echo(updateQuery($this->connection, $q));
        }
    }
    protected function do() {
        $user = getUser($_REQUEST["jwt"]);
        if (isset($_REQUEST["lambdaID"])) {
            $this->tagLambda();
        } elseif (isset($_REQUEST["refactoring"])) {
            $this->tagRefactoring();
        } else {
            echo('{"status":"ERROR", "message": "No lambda or refactoring ID provided."}');
        }
        
    }
    protected function name() : string {
        return "setTag";
    }
}
class GetEmailTemplateRefactoring extends Parameter {

    private function getEmailBody($templateVars, $target="a specific test") {
        $authorName = $templateVars["authorName"];
        $projectName = $templateVars["projectName"];
        $numberOfContributions = $templateVars["numberOfContributions"];
        $commitUrl = $templateVars["commitUrl"];
        
        extract($templateVars);

        return <<<EMAIL
        <p>Dear $authorName,</p>

        <p>We are a group of researchers from Concordia University, Montreal, Canada,
            investigating refactoring activity in test code.</p>

        <p>We found <b>$numberOfContributions</b> commits where you refactored test code in project <b>$projectName</b>. 
        Therefore, we consider you an expert on test refactoring, and we would like to ask you the 
            following questions about $target you recently refactored and can be inspected in 
            <a href="$commitUrl" target="_blank">$commitUrl</a>:
        </p>

        <ul>
            <li>Why did you refactor this test code?</li>
            <li>What kind of rectoring did you apply?</li>
            <li>Did you apply it manually or use an automated tool (e.g., IDE)?</li>
        </ul>

        <p>Your response will be anonymized in our study and will be used only for research purposes.</p>

        <p></p>
        <p>Thank you very much for your help and participation in our study.
            If you are interested in the results of this study, please let us know.
            We will contact you once the study is concluded.
            </p>

        <p style="margin-top: 30px">
            Victor Guerra Veloso (<a href="https://github.com/victorgveloso/" target="_blank">https://github.com/victorgveloso/</a>)<br />
            Nikolaos Tsantalis (<a href=" https://users.encs.concordia.ca/~nikolaos/" target="_blank">https://users.encs.concordia.ca/~nikolaos/</a>)<br />
            Tse-Hsun (Peter) Chen (<a href="https://petertsehsun.github.io/" target="_blank">https://petertsehsun.github.io/</a>)<br />
        </p>
    EMAIL;
    }
    protected function do() {
        $user = getUser($_REQUEST["jwt"]);
        $refactoringID = mysqli_real_escape_string($this->connection, urldecode($_REQUEST["refactoringID"]));
        $q = "SELECT r.authorName, r.authorEmail, r.project AS projectID, p.name AS projectName, 
        CONCAT(LEFT(p.cloneUrl, LENGTH(p.cloneUrl)-4),'/commit/',r.commitId, '#diff-', SHA2(c.filePath,256), 'R', c.startLine, '-R', c.endLine) AS refactoringDiffLink, c.filePath
        FROM refactoringgit r2
        INNER JOIN revisiongit r ON r2.revision = r.id  
        INNER JOIN projectgit p ON r.project = p.id 
        INNER JOIN coderangegit c ON c.refactoring = r2.id 
        WHERE r2.id = $refactoringID AND c.diffSide = 'RIGHT' LIMIT 1";

        $projectsInfo = getQueryRows($this->connection, $q);
        $authorEmail = $projectsInfo[0]["authorEmail"];
        $templateVars["authorName"] = $projectsInfo[0]["authorName"];

        $templateVars["commitUrl"] = $projectsInfo[0]["refactoringDiffLink"];
        $templateVars["projectName"] = $projectsInfo[0]["projectName"];

        $projectID = $projectsInfo[0]["projectID"];
        $templateVars["numberOfContributions"] = getCommitRefactoringsCount($this->connection, $projectID, $authorEmail, "");
        if(isset($_REQUEST["filePath"])) {
            $filePath = urldecode($_REQUEST["filePath"]);
            $offset = strrpos($filePath, "/") + 1;
            $fileExtensionLength = strlen(".java");
            $filePath = substr($filePath, $offset, strlen($filePath) - $fileExtensionLength - $offset);
            $template = json_encode($this->getEmailBody($templateVars, "the $filePath test"));
        } elseif ($projectsInfo[0]["filePath"] != "") {
            $filePath = $projectsInfo[0]["filePath"];
            $offset = strrpos($filePath, "/") + 1;
            $fileExtensionLength = strlen(".java");
            $filePath = substr($filePath, $offset, strlen($filePath) - $fileExtensionLength - $offset);
            $template = json_encode($this->getEmailBody($templateVars, "the $filePath test"));
        } else {
            $template = json_encode($this->getEmailBody($templateVars));
        }

        echo "{ \"template\": $template }";
    }
    protected function name() : string {
        return "getEmailTemplateRefactoring";
    }
}
class GetEmailTemplate extends Parameter {

    private function getEmailBody($templateVars) {
        
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

        <p>Therefore, we consider you are expert on Lambda expressions, and we would like to ask you the 
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
            Tse-Hsun (Peter) Chen (<a href="https://petertsehsun.github.io/" target="_blank">https://petertsehsun.github.io/</a>)<br />
        </p>
    EMAIL;
    }
    private function getRankString($number) {
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
    protected function do() {
        $user = getUser($_REQUEST["jwt"]);
        $lambdaID = mysqli_real_escape_string($this->connection, urldecode($_REQUEST["lambdaID"]));

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

        $projectsInfo = getQueryRows($this->connection, $q);

        $authorName = $projectsInfo[0]["authorName"];
        $templateVars["authorName"] = $authorName;

        $templateVars["commitUrl"] = $projectsInfo[0]["lambdaDiffLink"];
        $templateVars["projectName"] = $projectsInfo[0]["projectName"];

        $projectID = $projectsInfo[0]["projectID"];
        $templateVars["contributorRank"] = $this->getRankString(getAuthorRank($this->connection, $projectID, $authorName));
        
        $q = "SELECT p.lambdaDensityPerClass,
                (SELECT COUNT(*) FROM projectgit) projectsCount,
                (SELECT COUNT(*) FROM projectsadditionalinfo pi
                    WHERE pi.lambdaDensityPerClass > p.lambdaDensityPerClass) projectRank
                FROM projectsadditionalinfo p
                WHERE p.project = $projectID
        ";
        $projectsInfo = getQueryRows($this->connection, $q);

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

        $template = json_encode($this->getEmailBody($templateVars));

        echo "{ \"template\": $template }";
    }
    protected function name() : string {
        return "getEmailTemplate";
    }
}
class SendEmail extends Parameter {
    private function updateSameAuthorCommitStatus($connection, $commitID, $authorEmail) {
        $q = "UPDATE revisiongit SET status = 'AUTHOR_CONTACTED'
                WHERE revisiongit.authorEmail = '$authorEmail' AND revisiongit.status IN ('NEW', 'SEEN')";
        return updateQuery($connection, $q);
    }
    private function updateSameProjectOlderCommitStatus($connection, $commitID, $projectID) {
        $q = "UPDATE revisiongit SET status = 'SEEN'
              WHERE revisiongit.project = $projectID AND revisiongit.id  < $commitID AND revisiongit.status = 'NEW'";
        return updateQuery($connection, $q);
    }
    private function updateThisCommitStatus($connection, $commitID) {
        $q = "UPDATE revisiongit SET status = 'EMAIL_SENT'
                WHERE revisiongit.id = $commitID";
        return updateQuery($connection, $q);
    }
    private function doSendEmail($from, $fromName, $to, $subject, $body, $bcc) {
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
    protected function do() {
        $user = getUser($_REQUEST["jwt"]);
        $userFullName = $user->name . " " . $user->familyName;
        $userEmail = $user->email;
        $authorEmail = urldecode($_REQUEST["toEmail"]);
        $emailBody = urldecode($_REQUEST["body"]);
        $subject = urldecode($_REQUEST["subject"]);
        $authorName = urldecode($_REQUEST["to"]);
        $commitID = urldecode($_REQUEST["revision"]);
        $projectID = urldecode($_REQUEST["project"]);
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

        $result = $this->doSendEmail($userEmail, $userFullName, $authorEmail, $subject, $emailBody, $bcc);
        if ($result) {
            $emailBody = mysqli_real_escape_string($this->connection, $emailBody);
            $authorEmail = mysqli_real_escape_string($this->connection, $authorEmail);
            $lambdaID = mysqli_real_escape_string($this->connection, $lambdaID);
            $userEmail = mysqli_real_escape_string($this->connection, $userEmail);
            $subject = mysqli_real_escape_string($this->connection, $subject);
            $q = "";
            if (isset($_REQUEST["revision"])) {
                $revisionID = urldecode($_REQUEST["revision"]);
                $q = "INSERT INTO surveymail(`alternativeAddress`, `body`, `recipient`, `sentDate`, `sender`, `subject`, `revision`, `addedAt`)
                                    VALUES('', '$emailBody', '$authorEmail', NOW(), '$userEmail', '$subject', $revisionID, NOW())";
            }
            else {
            $q = "INSERT INTO surveymail(`alternativeAddress`, `body`, `recipient`, `sentDate`, `sender`, `subject`, `addedAt`)
                                VALUES('', '$emailBody', '$authorEmail', NOW(), '$userEmail', '$subject', NOW())";
            }
            $this->updateSameProjectOlderCommitStatus($this->connection, $commitID, $projectID);
            $this->updateSameAuthorCommitStatus($this->connection, $commitID, $authorEmail);
            $this->updateThisCommitStatus($this->connection, $commitID);
            echo(updateQuery($this->connection, $q));
            return;
        }

        echo('{"status":"ERROR"}');
    }
    protected function name() : string {
        return "sendEmail";
    }    
}
class AddResponseRefactoring extends Parameter {
    protected function do() {
        $user = getUser($_REQUEST["jwt"]);

        $emailBody = urldecode($_REQUEST["body"]);
        $emailBody = str_replace("\\\"", "\"", $emailBody);
        $emailBody = str_replace("\\'", "'", $emailBody);

        $userEmail = mysqli_real_escape_string($this->connection, $user->email);
        $revisionID = mysqli_real_escape_string($this->connection, urldecode($_REQUEST["revision"]));
        $authorEmail = mysqli_real_escape_string($this->connection, urldecode($_REQUEST["fromEmail"]));
        $emailBody = mysqli_real_escape_string($this->connection, $emailBody);
        $subject = mysqli_real_escape_string($this->connection, urldecode($_REQUEST["subject"]));
        $refactoringID = mysqli_real_escape_string($this->connection, urldecode($_REQUEST["refactoring"]));

        $q = "SELECT id FROM surveymail WHERE surveymail.revision = $revisionID AND surveymail.recipient = '$authorEmail'";

        $emailIDRows = getQueryRows($this->connection, $q);

        $emailID = $emailIDRows[0]["id"];

        $q = "INSERT INTO surveyresponse(`addedAt`,`sentDate`,`bodyHtml`,`bodyPlain`,`fromAddress`,`subject`,`survey`)
                    VALUES(NOW(), NOW(), '$emailBody', '$emailBody', '$authorEmail', '$subject', '$emailID')";
        
        echo(updateQuery($this->connection, $q));
    }
    protected function name() : string {
        return "addRefactoringResponse";
    }
}
class AddResponse extends Parameter {
    protected function do() {
        $user = getUser($_REQUEST["jwt"]);

        $emailBody = urldecode($_REQUEST["body"]);
        $emailBody = str_replace("\\\"", "\"", $emailBody);
        $emailBody = str_replace("\\'", "'", $emailBody);

        $userEmail = mysqli_real_escape_string($this->connection, $user->email);
        $authorEmail = mysqli_real_escape_string($this->connection, urldecode($_REQUEST["fromEmail"]));
        $emailBody = mysqli_real_escape_string($this->connection, $emailBody);
        $subject = mysqli_real_escape_string($this->connection, urldecode($_REQUEST["subject"]));
        $lambdaID = mysqli_real_escape_string($this->connection, urldecode($_REQUEST["lambda"]));

        $q = "INSERT INTO surveymail(`alternativeAddress`, `body`, `recipient`, `sentDate`, `sender`, `subject`)
                    VALUES('', '$emailBody', '$userEmail', NOW(), '$authorEmail', '$subject')";
        
        updateQuery($this->connection, $q);

        $emailID = $this->connection->insert_id;

        $q = "INSERT INTO lambdastable_surveymail (`lambdastable_id`, `surveyEmails_id`)
                    VALUES ($lambdaID, $emailID)";

        echo(updateQuery($this->connection, $q));
    }
    protected function name() : string {
        return "addResponse";
    }
}
class GetEmails  extends Parameter {
    protected function do() {
        $user = getUser($_REQUEST["jwt"]);
        if (isset($_REQUEST["lambdaID"])) {
            $lambdaID = mysqli_real_escape_string($this->connection, $_REQUEST["lambdaID"]);
            $q = "SELECT surveymail.*, (EXISTS(SELECT * FROM users WHERE users.email = surveymail.recipient)) recipientIsUser
                    FROM surveymail
                    INNER JOIN lambdastable_surveymail ON lambdastable_surveymail.surveyEmails_id = surveymail.id
                    WHERE lambdastable_surveymail.lambdastable_id = $lambdaID
                    ORDER BY surveymail.sentDate";

        } elseif (isset($_REQUEST["refactoring"])) {
            $email = mysqli_real_escape_string($this->connection, urldecode($_REQUEST["email"]));
            $refactoringID = $_REQUEST["refactoring"];
            $q = "  SELECT r.id AS refactoringId, sm.*, sr.id AS responseId, sr.bodyHtml, sr.subject AS responseSubject, sr.sentDate AS responseSentDate
                    FROM refactoringgit r 
                    INNER JOIN surveymail sm ON sm.revision = r.revision 
                    LEFT OUTER JOIN surveyresponse sr ON sr.survey = sm.id 
                    WHERE r.id = $refactoringID";
        } elseif (isset($_REQUEST["email"])) {
            $email = mysqli_real_escape_string($this->connection, urldecode($_REQUEST["email"]));
             $q = "SELECT surveymail.*, lambdastable_surveymail.lambdastable_id,
                        (EXISTS(SELECT * FROM users WHERE users.email = surveymail.recipient)) recipientIsUser
                    FROM surveymail
                    INNER JOIN lambdastable_surveymail ON lambdastable_surveymail.surveyEmails_id = surveymail.id
                    WHERE surveymail.recipient LIKE '$email' OR surveymail.sender LIKE '$email'
                    ORDER BY surveymail.sentDate";
        }
        echo(selectQuery($this->connection, $q));
    }
    protected function name() : string {
        return "getMails";
    }
    
}
class Signup extends Parameter {
    private function hashPassword($password) {
        $salt = "THEALMIGHTY";
        return sha1($password . $salt);
    }
    private function doRegister($connection, $username, $password) {
    
        $username = mysqli_real_escape_string($connection, strtolower($username));
        $password = $this->hashPassword($password);

        $q = "INSERT INTO users (userName,password) VALUES ('$username', '$password')";

        $userRow = updateQuery($connection, $q);
        $resp = json_decode($userRow);
        if ($resp->status == "OK") {
            $resp->credentials = array(
                "username" => $username,
                "password" => $password
            );
            echo json_encode($resp);
        } else {
            echo json_encode(array("status" => "UNAUTHORIZED"));
        }
    }
    protected function do() {
        $this->doRegister($this->connection, $_REQUEST["u"], $_REQUEST["p"]);
    }
    protected function name() : string {
        return "signup";
    }
}
class Login extends Parameter {
    private function hashPassword($password) {
        $salt = "THEALMIGHTY";
        return sha1($password . $salt);
    }
    private function doLogin($connection, $username, $password) {
    
        $username = mysqli_real_escape_string($connection, strtolower($username));
        $password = $this->hashPassword($password);
    
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
                
            $unencodedArray = array('jwt' => $jwt, 't' => $tokenId);
            echo json_encode($unencodedArray);
    
        } else {
            echo '{"status": "UNAUTHORIZED"}';
        }
    }
    protected function do() {
        $this->doLogin($this->connection, $_REQUEST["u"], $_REQUEST["p"]);
    }
    public function isEnabled() : bool {
        return parent::isEnabled() && isset($_REQUEST["u"]) && isset($_REQUEST["p"]);
    }
    protected function name() : string {
        return "login";
    }
}
class Sha extends Parameter {
    protected function do() {
    }
    protected function name() : string {
        return "sha";
    }
}
function getSecretKey() {
    //die(base64_encode(openssl_random_pseudo_bytes(256)));
    return "nvolp5iDf4KkqVJ8n35nSLA+JogDD4gzH+Q9aSDPIHvgvpmYA9mbXLuj2LGiYSw+MSHGhSJaxEWXiG9nzLYFH/6VF9jpADp7yV38oCBVjaOD5f2BUtz2Ni3J0fUV9wSVr7e5RDwUgLPZnI5ItZmxt3kZFXMTD+DgwUorQTW4ropCkS9bdbKTxKvFN2WcU/YGsOrgdC1+/M/IZz4w4h4WLGUpWZ02XVfOrYVQcgHb3xmUZRdtO9sbb+4zxrfP7FvFQ9p7CfEzyiMmpayVqQo28DNz4RxA70M6M1xTbpwZev1Wp/GJ8EuFC307U+A8F1LS6Us0ApsWC0sT6Bfz4Cztkw==";
}
function unauthorized() {
    header('HTTP/1.0 401 Unauthorized');
    die();
}
?>