<?php

use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use DI\Container;
use Valitron\Validator;
use Carbon\Carbon;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

require_once __DIR__ . '/../vendor/autoload.php';

session_start();

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});
$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});
$container->set('pdo', function () {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();

    $databaseUrl = parse_url($_ENV['DATABASE_URL']);
    var_dump($_ENV);
    if (!$databaseUrl) {
        throw new \Exception("Error reading database configuration file");
    }
    $dbHost = $databaseUrl['host'];
    $dbPort = $databaseUrl['port'];
    $dbName = ltrim($databaseUrl['path'], '/');
    $dbUser = $databaseUrl['user'];
    $dbPassword = $databaseUrl['pass'];

    $conStr = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
        $dbHost,
        $dbPort,
        $dbName,
        $dbUser,
        $dbPassword
    );

    $pdo = new \PDO('pgsql:host=localhost;dbname=urls', 'ishell90', '159753zxc');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

    return $pdo;
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->add(MethodOverrideMiddleware::class);
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'index.phtml');
})->setName('main');

$app->post('/urls', function ($request, $response) use ($router) {
    $formData = $request->getParsedBody()['url'];

    $validator = new Validator($formData);
    $validator->rule('required', 'name')->message('URL не должен быть пустым');
    $validator->rule('url', 'name')->message('Некорректный URL');
    $validator->rule('lengthMax', 'name', 255)->message('Некорректный URL');

    if ($validator->validate()) {
        try {
            $pdo = $this->get('pdo');

            $url = strtolower($formData['name']);
            $parsedUrl = parse_url($url);
            $urlName = "{$parsedUrl['scheme']}://{$parsedUrl['host']}";
            $created_at = Carbon::now();

            $queryUrl = "SELECT name FROM urls WHERE name = ?";
            $stmt = $pdo->prepare($queryUrl);
            $stmt->execute([$urlName]);
            $selectedUrl = $stmt->fetchAll();

            if (count($selectedUrl) > 0) {
                $queryId = 'SELECT id FROM urls WHERE name = ?';
                $stmt = $pdo->prepare($queryId);
                $stmt->execute([$urlName]);
                $selectId = (string) $stmt->fetchColumn();

                $this->get('flash')->addMessage('success', 'Страница уже существует');
                return $response->withRedirect($router->urlFor('url', ['id' => $selectId]));
            }

                $sql = "INSERT INTO urls (name, created_at) VALUES (?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$urlName, $createdAt]);
                $lastInsertId = (string) $pdo->lastInsertId();

                $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
                return $response->withRedirect($router->urlFor('url', ['id' => $lastInsertId]));

        } catch (\Throwable | \PDOException $e) {
            echo $e->getMessage();
        }
    }

    $errors = $validator->errors();
    $params = [
        'url'=>$formData['name'],
        'errors'=>$errors,
        'invalidForm' => 'is-invalid'
    ];
    
    $response = $response->withStatus(422);
    return $this->get('renderer')->render($response, 'index.phtml', $params);
});

$app->get('/urls', function ($request, $response) {
    $pdo = $this->get('pdo');
    $queryUrl = 'SELECT id, name FROM urls ORDER BY created_at DESC';
    $stmt = $pdo->prepare($queryUrl);
    $stmt->execute();
    $selectedUrls = $stmt->fetchAll(\PDO::FETCH_UNIQUE);

    $queryChecks = 'SELECT 
    url_id, 
    created_at, 
    status_code, 
    h1, 
    title, 
    description 
    FROM url_checks';
    $stmt = $pdo->prepare($queryChecks);
    $stmt->execute();
    $selectedChecks = $stmt->fetchAll(\PDO::FETCH_UNIQUE);

    foreach ($selectedChecks as $key => $value) {
        if (array_key_exists($key, $selectedUrls)) {
            $selectedUrls[$key] = array_merge($selectedUrls[$key], $value);
        }
    }

    $params = [
        'data' => $selectedUrls
    ];
    return $this->get('renderer')->render($response, 'urls.phtml', $params);
})->setName('urls');

$app->run();
