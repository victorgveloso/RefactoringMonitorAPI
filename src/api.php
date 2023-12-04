<?php

    require_once "php-jwt/JWT.php";
    require_once "php-jwt/SignatureInvalidException.php";
    require_once "php-jwt/ExpiredException.php";
    require_once "php-jwt/BeforeValidException.php";
    require_once 'PHPMailer/PHPMailerAutoload.php';
    require_once 'sql.php';
    require_once 'params.php';
    ini_set('memory_limit', '1024M');
    $DATABASE_NAME = getenv('MYSQL_DATABASE') ?: 'refactoring';
    $globalConnection = new mysqli("mysql", "myuser", "mypassword", $DATABASE_NAME);
    //$connection = new mysqli("db-user-public-my51.encs.concordia.ca", "refactor_admin", "dud4M8G$6y54", "refactoring");
    //$connection = new mysqli("127.0.0.1", "davood", "123456", "lambda-study");

    const AllLambdas = 0;
    const OnlyEmailedLambdas = 1;
    const ImInvolvedInLambdas = 2;
    const OnlyEmailedByOthersLambdas = 3;

    header("Access-Control-Allow-Origin: *");
    header("Content-type: application/json");

    $paramsProcessor = new Projects(new Lambdas(new EmailedLambdas(new MonitorProject(new SkipLambda(new AllTags(new TagsFor(new SetTag(new GetEmailTemplate(new SendEmail(new AddResponse(new GetEmails(new Login(new Signup(new Refactorings(new GetEmailTemplateRefactoring(new EmailedRefactorings(new CodeRange())))))))))))))))));
    $paramsProcessor->handle();
   
?>
