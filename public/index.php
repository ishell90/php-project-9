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
use DiDom\Document;

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
    $dotenv = Dotenv::createImmutable(__DIR__ . './../');
    $dotenv->safeLoad();

    $databaseUrl = parse_url($_ENV['DATABASE_URL']);
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
    
    $pdo = new \PDO($conStr);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    
    return $pdo;
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->add(MethodOverrideMiddleware::class);
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$customErrorHandler = function () use ($app) {
    $response = $app->getResponseFactory()->createResponse();
    return $this->get('renderer')->render($response, "error404.phtml");
};
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

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
            $createdAt = Carbon::now();

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
        'url' => $formData['name'],
        'errors' => $errors,
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

$app->get('/urls/{$id}', function ($request, $response, $args) {
    $pdo = $this->get('pdo');
    $id = $args['id'];
    $alert = '';
    $messages = $this->get('flash')->getMessages();
    
    switch(key($messages)) {
        case ('success'):
            $alert = 'success';
            break;
        case ('error'):
            $alert = 'error';
            break;
        case ('danger'):
            $alert = 'danger';
            break;
    };

    $query = 'SELECT * FROM urls WHERE id = ?';
    $stmt = $pdo->prepare($query);
    $stmt->execute([$id]);
    $select = $stmt->fetch();

    if ($select) {
        $queryCheck = 'SELECT * FROM url_check WHERE id = ? ORDER BY created_at DESC';
        $stmt = $pdo->prepare($queryCheck);
        $stmt->execute([$id]);
        $selectedCheck = $stmt->fetchAll();
    }

    $params = [
        'alert' => $alert,
        'flash' => $messages,
        'data' => $select,
        'checkData' => $selectedCheck
    ];

    return $this->get('renderer')->render($response, 'url.phtml', $params);
})->setName('url');

$app->post('/urls/{url_id}/check', function ($request, $response, $args) use ($router) {
    $id = $args['url_id'];

    try {
        $pdo = $this->get('pdo');

        $querySql = "SELECT name FROM urls WHERE id = ?";
        $stmt = $pdo->prepare($querySql);
        $stmt->execute([$id]);
        $selectedUrl = $stmt->fetch(\PDO::FETCH_COLUMN);

        $createdAt = Carbon::now();

        $client = $this->get('client');

        try {
            $res = $client->get($selectedUrl);
            $this->get('flash')->addMessage('success', 'Страница успешно проверена');
        } catch (RequestException $e) {
            $res = $e->getResponse();
            $this->get('flash')->clearMessages();
            $errorMessage = 'Проверка была выполнена успешно, но сервер ответил c ошибкой';
            $this->get('flash')->addMessage('error', $errorMessage);
        } catch (ConnectException $e) {
            $errorMessage = 'Произошла ошибка при проверке, не удалось подключиться';
            $this->get('flash')->addMessage('danger', $errorMessage);
            return $response->withRedirect($router->urlFor('url', ['id' => $id]));
        }

        $htmlBody = !is_null($res) ? $res->getBody() : '';
        /** @var Document $document */
        $document = !is_null($res) ? new Document((string) $htmlBody) : '';
        $statusCode = !is_null($res) ? $res->getStatusCode() : null;
        $h1 = !is_null($res) ? optional($document->first('h1'))->text() : '';
        $title = !is_null($res) ? optional($document->first('title'))->text() : '';
        $description = !is_null($res) ? optional($document->first('meta[name="description"]'))->getAttribute('content') : '';

        $sql = "INSERT INTO url_checks (
            url_id, 
            created_at, 
            status_code, 
            h1, 
            title, 
            description) 
            VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id, $createdAt, $statusCode, $h1, $title, $description]);
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }

    return $response->withRedirect($router->urlFor('url', ['id' => $id]));

});

$app->run();
